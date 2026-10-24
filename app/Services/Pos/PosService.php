<?php

namespace App\Services\Pos;

use App\Support\Money;
use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\FolioCharge;
use App\Models\HotelBankAccount;
use App\Models\Pos\PosCategory;
use App\Models\Pos\PosItem;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosOutlet;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Inventory\StockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Bar & restaurant sales.
 *
 * Tills work offline: they keep the menu and the in-house guest list on the
 * device, create each sale there, and send it here when the network allows
 * (see sync()). A sale has already happened by the time it arrives — the drink
 * is drunk, the cash is in the drawer — so the server never throws a sale
 * away: if it can't be settled as sent (e.g. a room charge for a booking that
 * was cancelled meanwhile) it is kept and flagged "Needs attention".
 */
class PosService
{
    public function __construct(private FolioService $folios, private StockService $stock)
    {
    }

    // ── Prices ────────────────────────────────────────────────────────

    /**
     * Totals for a list of lines [{charge_type, quantity, unit_price}] and an
     * order discount. The till runs the same maths in JavaScript (pos.js).
     * Each bill category is taxed separately, the same way it is posted to a
     * guest bill, so a room charge always matches the till total.
     */
    public static function price(array $lines, float $discount = 0): array
    {
        $groups = [];
        foreach ($lines as $l) {
            $lineTotal = Money::round((float) $l['quantity'] * (float) $l['unit_price']);
            $groups[$l['charge_type']] = Money::round(($groups[$l['charge_type']] ?? 0) + $lineTotal);
        }
        $subtotal = Money::round(array_sum($groups));
        $discount = Money::round(min(max($discount, 0), $subtotal));

        $parts = array_map(fn ($gross) => FolioCharge::computeTax($gross), $groups);
        if ($discount > 0) {
            $parts['discount'] = FolioCharge::computeTax(-$discount);
        }

        $breakdown = [];
        foreach ($parts as $p) {
            foreach ($p['breakdown'] as $t) {
                $breakdown[$t['name']] ??= ['name' => $t['name'], 'rate' => $t['rate'], 'amount' => 0];
                $breakdown[$t['name']]['amount'] = Money::round($breakdown[$t['name']]['amount'] + $t['amount']);
            }
        }

        return [
            'groups'    => $groups,
            'subtotal'  => $subtotal,
            'discount'  => $discount,
            'tax'       => Money::round(array_sum(array_column($parts, 'tax'))),
            'breakdown' => array_values($breakdown),
            'total'     => Money::round(array_sum(array_column($parts, 'total'))),
        ];
    }

    // ── What a till needs to work offline ─────────────────────────────

    public function terminalData(User $user): array
    {
        $outlets = PosOutlet::active()->ordered()->get();
        $items = PosItem::active()->with('outlets:id')->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();
        $canRoom = $user->can('Charge to room');

        return [
            'generated_at' => now()->toIso8601String(),
            'hotel'        => ['name' => config('hotel.name'), 'currency' => config('hotel.currency_symbol')],
            'tax'          => ['inclusive' => (bool) config('hotel.prices_include_tax', true), 'taxes' => array_values(config('hotel.taxes', []))],
            'user'         => [
                'id' => $user->id, 'name' => $user->name,
                'can' => [
                    'discount' => $user->can('Give POS discounts'),
                    'room'     => $canRoom,
                    'void'     => $user->can('Void POS orders'),
                    'reports'  => $user->can('View POS reports'),
                    'menu'     => $user->can('Manage POS menu'),
                ],
            ],
            'outlets' => $outlets->map(fn (PosOutlet $o) => [
                'id' => $o->id, 'name' => $o->name, 'code' => $o->code, 'type' => $o->type, 'color' => $o->color,
                'room' => $o->allow_room_charge, 'footer' => $o->receipt_footer,
            ])->values(),
            'categories' => PosCategory::where('is_active', true)->ordered()->get(['id', 'name', 'color'])->values(),
            'items' => $items->map(fn (PosItem $i) => [
                'id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'price' => (float) $i->price, 'category_id' => $i->category_id,
                'charge_type' => $i->charge_type->value, 'outlets' => $i->outlets->pluck('id')->values(),
            ])->values(),
            'rooms' => $canRoom ? $this->inHouse() : [],
            'bank_accounts' => HotelBankAccount::forTransfers()->map(fn ($a) => ['id' => $a->id, 'label' => $a->bank_name . ' · ' . $a->account_number])->values(),
            'methods' => collect([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer])
                ->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()])->values(),
        ];
    }

    /** Guests who can charge to their room right now. */
    public function inHouse(): array
    {
        return Reservation::inHouse()->with(['guest', 'rooms.room'])->get()
            ->map(function (Reservation $r) {
                $numbers = $r->rooms->pluck('room.number')->filter()->values();

                return [
                    'reservation_id' => $r->id,
                    'reference'      => $r->reference,
                    'guest'          => $r->guest->full_name,
                    'vip'            => (bool) $r->guest->is_vip,
                    'rooms'          => $numbers,
                    'departs'        => $r->check_out_date->toDateString(),
                ];
            })
            ->sortBy(fn ($x) => $x['rooms'][0] ?? 'zzz', SORT_NATURAL)->values()->all();
    }

    // ── Receiving sales from tills ────────────────────────────────────

    /**
     * Store a batch of sales sent by a till. Safe to call again with the same
     * sales: each uuid is stored once. Returns one result per sale:
     *   ok        stored now (status paid / charged / attention)
     *   duplicate already stored earlier — the till can mark it as sent
     *   rejected  unreadable — the till keeps it and shows it to a supervisor
     */
    public function sync(array $orders, User $by): array
    {
        return array_map(function ($payload) use ($by) {
            $uuid = is_array($payload) ? (string) ($payload['uuid'] ?? '') : '';
            try {
                return $this->receive(is_array($payload) ? $payload : [], $by);
            } catch (\Throwable $e) {
                Log::error('POS sync failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

                return ['uuid' => $uuid, 'result' => 'rejected', 'message' => 'The server could not save this sale: ' . $e->getMessage()];
            }
        }, array_values($orders));
    }

    private function receive(array $p, User $by): array
    {
        $v = Validator::make($p, [
            'uuid'              => 'required|uuid',
            'number'            => 'required|string|max:40',
            'outlet_id'         => 'required|integer|exists:pos_outlets,id',
            'ordered_at'        => 'required|date',
            'method'            => ['required', Rule::in(array_merge(array_map(fn ($m) => $m->value, PaymentMethod::cases()), [PosOrder::ROOM]))],
            'reservation_id'    => 'nullable|required_if:method,room|integer',
            'room_number'       => 'nullable|string|max:20',
            'guest_name'        => 'nullable|string|max:120',
            'table_label'       => 'nullable|string|max:40',
            'reference'         => 'nullable|string|max:100',
            'bank_account_id'   => 'nullable|integer',
            'tendered'          => 'nullable|numeric|min:0',
            'discount_amount'   => 'nullable|numeric|min:0',
            'discount_reason'   => 'nullable|string|max:255',
            'total'             => 'nullable|numeric',
            'cashier_id'        => 'nullable|integer',
            'device_id'         => 'nullable|string|max:20',
            'was_offline'       => 'nullable|boolean',
            'items'             => 'required|array|min:1|max:200',
            'items.*.item_id'   => 'nullable|integer',
            'items.*.name'      => 'required|string|max:120',
            'items.*.charge_type' => ['required', Rule::in(array_map(fn ($t) => $t->value, PosItem::CHARGE_TYPES))],
            'items.*.quantity'  => 'required|numeric|min:0.01|max:9999',
            'items.*.unit_price' => 'required|numeric|min:0|max:99999999',
            'items.*.note'      => 'nullable|string|max:120',
        ]);
        if ($v->fails()) {
            return ['uuid' => $p['uuid'] ?? '', 'result' => 'rejected', 'message' => implode(' ', $v->errors()->all())];
        }
        $p = $v->validated();

        if ($existing = PosOrder::where('uuid', $p['uuid'])->first()) {
            return $this->result($existing, 'duplicate');
        }

        try {
            $order = DB::transaction(fn () => $this->store($p, $by));
        } catch (UniqueConstraintViolationException $e) {
            // Two copies of the same sale arrived at the same moment.
            if ($existing = PosOrder::where('uuid', $p['uuid'])->first()) {
                return $this->result($existing, 'duplicate');
            }
            throw $e;
        }

        return $this->result($order, 'ok');
    }

    private function store(array $p, User $by): PosOrder
    {
        $notes = [];
        $outlet = PosOutlet::findOrFail($p['outlet_id']);

        // Who rang it up: the cashier signed in on the till at the time.
        $cashier = ! empty($p['cashier_id']) ? User::find($p['cashier_id']) : null;
        if (! $cashier || ! $cashier->can('Use POS')) {
            $cashier = $by;
        }

        $price = self::price($p['items'], (float) ($p['discount_amount'] ?? 0));
        if ($price['discount'] > 0 && ! $cashier->can('Give POS discounts')) {
            $notes[] = 'Discount given by someone without discount permission.';
        }
        if (isset($p['total']) && abs((float) $p['total'] - $price['total']) > 0.009) {
            $notes[] = sprintf('Till showed %s; server total is %s.', $this->folios->money((float) $p['total']), $this->folios->money($price['total']));
        }

        $orderedAt = Carbon::parse($p['ordered_at'])->setTimezone(config('app.timezone'));
        if ($orderedAt->gt(now()->addMinutes(10))) {
            $notes[] = 'Till clock was ahead (' . $orderedAt->format('j M H:i') . ').';
            $orderedAt = now();
        }

        $number = $p['number'];
        if (PosOrder::where('number', $number)->exists()) {
            $number .= '-' . strtoupper(substr($p['uuid'], 0, 4));
        }

        $isRoom = $p['method'] === PosOrder::ROOM;
        $method = $isRoom ? null : PaymentMethod::from($p['method']);
        $tendered = $method === PaymentMethod::Cash && isset($p['tendered']) ? Money::round((float) $p['tendered']) : null;

        $order = PosOrder::create([
            'uuid'            => $p['uuid'],
            'number'          => $number,
            'outlet_id'       => $outlet->id,
            'status'          => $isRoom ? PosOrder::ATTENTION : PosOrder::PAID, // room charges are settled below
            'method'          => $p['method'],
            'bank_account_id' => $method === PaymentMethod::Transfer && ! empty($p['bank_account_id']) && HotelBankAccount::whereKey($p['bank_account_id'])->exists() ? $p['bank_account_id'] : null,
            'reference'       => $p['reference'] ?? null,
            // Kept even if the charge fails, so a supervisor can see who it was meant for.
            'reservation_id'  => $isRoom && Reservation::whereKey($p['reservation_id'])->exists() ? $p['reservation_id'] : null,
            'room_number'     => $p['room_number'] ?? null,
            'guest_name'      => $p['guest_name'] ?? null,
            'table_label'     => $p['table_label'] ?? null,
            'subtotal'        => $price['subtotal'],
            'discount_amount' => $price['discount'],
            'discount_reason' => $price['discount'] > 0 ? ($p['discount_reason'] ?? null) : null,
            'tax_amount'      => $price['tax'],
            'tax_breakdown'   => $price['breakdown'],
            'total'           => $price['total'],
            'tendered'        => $tendered,
            'change_due'      => $tendered !== null ? max(0, Money::round($tendered - $price['total'])) : null,
            'items_count'     => (int) ceil(array_sum(array_column($p['items'], 'quantity'))),
            'cashier_id'      => $cashier->id,
            'synced_by'       => $by->id,
            'device_id'       => $p['device_id'] ?? null,
            'ordered_at'      => $orderedAt,
            'synced_at'       => now(),
            'was_offline'     => (bool) ($p['was_offline'] ?? false),
        ]);

        foreach ($p['items'] as $l) {
            $order->items()->create([
                'item_id'     => ! empty($l['item_id']) && PosItem::whereKey($l['item_id'])->exists() ? $l['item_id'] : null,
                'name'        => $l['name'],
                'charge_type' => $l['charge_type'],
                'quantity'    => $l['quantity'],
                'unit_price'  => $l['unit_price'],
                'total'       => Money::round((float) $l['quantity'] * (float) $l['unit_price']),
                'note'        => $l['note'] ?? null,
            ]);
        }

        // Take the ingredients out of the outlet's store (recipes). Sales are never refused for stock.
        $this->stock->consumePosOrder($order);

        if ($isRoom) {
            $problem = $this->roomChargeProblem($order, (int) $p['reservation_id'], $cashier, $outlet);
            if ($problem) {
                $notes[] = $problem;
            } else {
                $note = $this->chargeToRoom($order, Reservation::find($p['reservation_id']));
                if ($note) {
                    $notes[] = $note;
                }
            }
        }

        if ($notes) {
            $order->forceFill(['sync_note' => mb_substr(implode(' ', $notes), 0, 255)])->save();
        }

        return $order;
    }

    /** Why a sale can't go on this booking's bill, or null if it can. */
    private function roomChargeProblem(PosOrder $order, int $reservationId, User $cashier, PosOutlet $outlet): ?string
    {
        $r = Reservation::find($reservationId);
        if (! $r) {
            return 'Room charge: the booking no longer exists.';
        }
        if (! $outlet->allow_room_charge) {
            return "Room charge: {$outlet->name} doesn't allow room charges.";
        }
        if (! $cashier->can('Charge to room')) {
            return 'Room charge: ' . $cashier->name . ' is not allowed to charge to rooms.';
        }
        if (! in_array($r->status, [ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true)) {
            return "Room charge: booking {$r->reference} is " . strtolower($r->status->label()) . '.';
        }

        return null;
    }

    /** Put the sale on the guest's bill — one line per bill category, plus the discount. */
    private function chargeToRoom(PosOrder $order, Reservation $r): ?string
    {
        $folio = $this->folios->forReservation($r);
        $order->loadMissing(['items', 'outlet']);
        $date = $order->ordered_at->toDateString();
        $source = ['source_type' => PosOrder::class, 'source_id' => $order->id];

        foreach ($order->items->groupBy('charge_type') as $type => $lines) {
            $summary = $lines->map(fn ($l) => rtrim(rtrim(number_format((float) $l->quantity, 2), '0'), '.') . '× ' . $l->name)->join(', ');
            $this->folios->postCharge($folio, ChargeType::from($type), mb_substr("{$order->outlet->name} {$order->number}: {$summary}", 0, 255),
                1, (float) $lines->sum('total'), $date, $source + ['idempotency_key' => "pos:{$order->uuid}:{$type}"]);
        }
        if ((float) $order->discount_amount > 0) {
            $this->folios->postCharge($folio, ChargeType::Discount, "{$order->outlet->name} {$order->number}: discount" . ($order->discount_reason ? ' — ' . $order->discount_reason : ''),
                1, (float) $order->discount_amount, $date, $source + ['idempotency_key' => "pos:{$order->uuid}:discount"]);
        }

        $order->forceFill([
            'status'         => PosOrder::CHARGED,
            'reservation_id' => $r->id,
            'folio_id'       => $folio->id,
            'room_number'    => $order->room_number ?: $r->rooms()->with('room')->get()->pluck('room.number')->filter()->join(', '),
            'guest_name'     => $order->guest_name ?: $r->guest->full_name,
        ])->save();

        return $r->status === ReservationStatus::CheckedOut
            ? "Guest had already checked out — added to their bill, which now shows a balance."
            : null;
    }

    private function result(PosOrder $order, string $result): array
    {
        return [
            'uuid'    => $order->uuid,
            'result'  => $result,
            'id'      => $order->id,
            'number'  => $order->number,
            'status'  => $order->status,
            'message' => $order->sync_note,
        ];
    }

    // ── After the sale ────────────────────────────────────────────────

    public function void(PosOrder $order, string $reason, User $by): void
    {
        if ($order->isVoided()) {
            throw new BookingException('This order is already voided.');
        }

        DB::transaction(function () use ($order, $reason, $by) {
            $this->stock->reversePosOrder($order);
            foreach ($order->folioCharges()->whereNull('voided_at')->get() as $charge) {
                $this->folios->voidCharge($charge, "POS order {$order->number} voided: {$reason}");
            }
            $order->forceFill(['status' => PosOrder::VOIDED, 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();
        });
    }

    /** Settle a sale that needs attention: take payment at the till, or put it on a (different) guest bill. */
    public function resolve(PosOrder $order, array $how, User $by): void
    {
        if ($order->status !== PosOrder::ATTENTION) {
            throw new BookingException('This order is already settled.');
        }

        DB::transaction(function () use ($order, $how, $by) {
            if (($how['method'] ?? null) === PosOrder::ROOM) {
                $r = Reservation::findOrFail($how['reservation_id']);
                if (! in_array($r->status, [ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true)) {
                    throw new BookingException("Booking {$r->reference} is " . strtolower($r->status->label()) . ' — choose a guest who is staying or has stayed.');
                }
                $order->room_number = null;
                $order->guest_name = null;
                $this->chargeToRoom($order, $r);
            } else {
                $order->forceFill(['status' => PosOrder::PAID, 'method' => $how['method'], 'reference' => $how['reference'] ?? $order->reference])->save();
            }
            $order->forceFill(['resolved_at' => now(), 'resolved_by' => $by->id])->save();
        });
    }
}
