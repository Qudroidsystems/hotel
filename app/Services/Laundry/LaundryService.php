<?php

namespace App\Services\Laundry;

use App\Enums\ChargeType;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Laundry\LaundryItem;
use App\Models\Laundry\LaundryOrder;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Guest laundry. A checked-in guest asks for their bag to be collected (from the guest app or their booking
 * page), listing what is in it or not. Staff collect it and count it: the count, at the price list's prices,
 * goes on the room bill there and then, and the guest is told when to expect it back (express is quicker and
 * costs more). Then "ready" and "returned". Bags handed in at the desk are counted straight away.
 */
class LaundryService
{
    public const MAX_LINES = 40;
    public const MAX_QUANTITY = 50;
    public const MAX_WAITING = 2;   // bags a room can have waiting to be collected at once

    public function __construct(private FolioService $folios)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.laundry.enabled', true);
    }

    public function items(): Collection
    {
        return LaundryItem::active()->ordered()->get();
    }

    /** Why this stay can't send laundry now (null = it can). */
    public function blocker(Reservation $r): ?string
    {
        if (! $this->enabled() || ! LaundryItem::active()->exists()) {
            return 'Laundry isn’t offered through the app at this hotel.';
        }
        if ($r->status !== ReservationStatus::CheckedIn) {
            return 'You can send laundry once you’ve checked in.';
        }

        return null;
    }

    public function canAsk(Reservation $r): bool
    {
        return $this->blocker($r) === null;
    }

    public function canHandle(User $user): bool
    {
        return $user->can('Handle laundry');
    }

    // ── Hours and timing ──────────────────────────────────────────────

    /** [from, to] "HH:MM" when bags are collected, or null when any time. */
    public function hours(): ?array
    {
        $h = (string) config('hotel.laundry.collection_hours', '');

        return preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $h, $m) ? [$m[1], $m[2]] : null;
    }

    public function collectingNow(?Carbon $at = null): bool
    {
        if (! $h = $this->hours()) {
            return true;
        }
        $now = ($at ?? now())->format('H:i');

        return $h[0] <= $h[1] ? ($now >= $h[0] && $now < $h[1]) : ($now >= $h[0] || $now < $h[1]);
    }

    public function hoursLabel(): ?string
    {
        if (! $h = $this->hours()) {
            return null;
        }
        $f = fn ($t) => Carbon::createFromFormat('H:i', $t)->format('g:i A');

        return $f($h[0]) . ' – ' . $f($h[1]);
    }

    public function turnaroundHours(bool $express): int
    {
        return max(1, (int) ($express ? config('hotel.laundry.express_hours', 4) : config('hotel.laundry.standard_hours', 24)));
    }

    public function expressPercent(): float
    {
        return max(0, (float) config('hotel.laundry.express_percent', 50));
    }

    /** "Back by Wed 2:00 PM", worked out from when the bag is collected. */
    public function dueAt(Carbon $collected, bool $express): Carbon
    {
        return $collected->copy()->addHours($this->turnaroundHours($express));
    }

    // ── Prices ────────────────────────────────────────────────────────

    /**
     * Check and price a list [{item_id, service, quantity}] at today's price list (never what a phone sent).
     * Lines for the same item and service are added together.
     */
    public function priceLines(array $lines): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => is_array($l) && (int) ($l['quantity'] ?? 0) > 0));
        if (count($lines) > self::MAX_LINES) {
            throw new BookingException('That’s a lot of laundry — please call housekeeping.');
        }
        $items = LaundryItem::active()->whereIn('id', array_map(fn ($l) => (int) ($l['item_id'] ?? 0), $lines))->get()->keyBy('id');
        $out = [];
        foreach ($lines as $l) {
            $item = $items->get((int) ($l['item_id'] ?? 0));
            $service = (string) ($l['service'] ?? 'wash');
            $price = $item?->priceFor($service);
            if (! $item || $price === null) {
                throw new BookingException('Something on your list is no longer on the price list. Pull down to refresh.');
            }
            $key = $item->id . ':' . $service;
            $qty = min(self::MAX_QUANTITY, max(1, (int) $l['quantity']));
            if (isset($out[$key])) {
                $out[$key]['quantity'] = min(self::MAX_QUANTITY, $out[$key]['quantity'] + $qty);
                continue;
            }
            $out[$key] = ['item_id' => $item->id, 'name' => $item->name, 'service' => $service, 'quantity' => $qty, 'unit_price' => $price];
        }

        return array_values($out);
    }

    /** ['subtotal', 'express_fee', 'total'] */
    public function totals(array $priced, bool $express): array
    {
        $sub = Money::round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $priced)));
        $fee = $express ? Money::round($sub * $this->expressPercent() / 100) : 0.0;

        return ['subtotal' => $sub, 'express_fee' => $fee, 'total' => Money::round($sub + $fee)];
    }

    // ── The guest ─────────────────────────────────────────────────────

    /**
     * The guest asks for their laundry to be collected. The list is optional ("just collect my bag"): staff count
     * it either way, and the count is what is charged.
     */
    public function request(Reservation $r, array $lines, bool $express = false, ?string $note = null, string $via = 'app'): LaundryOrder
    {
        if ($why = $this->blocker($r)) {
            throw new BookingException($why);
        }
        $waiting = LaundryOrder::where('reservation_id', $r->id)->where('status', LaundryOrder::REQUESTED)->count();
        if ($waiting >= self::MAX_WAITING) {
            throw new BookingException('Your laundry is already waiting to be collected. Add anything else to the same bag.');
        }
        $priced = $this->priceLines($lines);
        $r->loadMissing('guest', 'rooms.room');

        $order = LaundryOrder::create([
            'reservation_id' => $r->id,
            'room_number'    => $r->rooms->pluck('room.number')->filter()->implode(', ') ?: null,
            'guest_name'     => $r->guest?->full_name,
            'lines'          => $priced,
            'asked'          => $priced ?: null,
            'express'        => $express,
            'note'           => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null,
            'status'         => LaundryOrder::REQUESTED,
            'via'            => in_array($via, ['app', 'web'], true) ? $via : 'app',
        ] + $this->totals($priced, $express));
        $r->log('laundry', "Laundry {$order->number} to collect" . ($priced ? ': ' . $order->summary : '') . ($express ? ' (express)' : '') . '.');

        return $order->fresh();
    }

    public function cancelByGuest(LaundryOrder $order): void
    {
        $updated = LaundryOrder::whereKey($order->id)->where('status', LaundryOrder::REQUESTED)->update(['status' => LaundryOrder::CANCELLED, 'updated_at' => now()]);
        if (! $updated) {
            throw new BookingException('Your laundry has already been collected — call housekeeping if you need it back.');
        }
    }

    // ── Staff ─────────────────────────────────────────────────────────

    /**
     * Collect and count the bag. $lines is the count (null = the guest's own list, as it is). The count goes on
     * the room bill now.
     */
    public function collect(LaundryOrder $order, User $by, ?array $lines = null, ?bool $express = null, ?string $note = null): LaundryOrder
    {
        $this->authorize($by);

        return DB::transaction(function () use ($order, $by, $lines, $express, $note) {
            $order = LaundryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== LaundryOrder::REQUESTED) {
                throw new BookingException("{$order->number} has already been dealt with. Refresh the list.");
            }
            $priced = $lines === null ? $order->lines : $this->priceLines($lines);
            if (! $priced) {
                throw new BookingException('Count what is in the bag first.');
            }
            $express ??= $order->express;
            $differs = $order->asked && LaundryOrder::describe($order->asked) !== LaundryOrder::describe($priced);
            $order->fill(['lines' => $priced, 'express' => $express] + $this->totals($priced, $express));
            $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;
            if ($differs && ! $note) {
                $note = 'We counted ' . $order->pieces . ' ' . str('piece')->plural($order->pieces) . ' — your list said ' . (int) collect($order->asked)->sum('quantity') . '.';
            }

            return $this->checkIn($order, $by, $note);
        });
    }

    /** A bag handed in at the desk (or found on the door handle): counted and charged at once. */
    public function deskOrder(Reservation $r, User $by, array $lines, bool $express = false, ?string $note = null): LaundryOrder
    {
        $this->authorize($by);
        if ($r->status !== ReservationStatus::CheckedIn) {
            throw new BookingException('Only in-house guests can send laundry to the room bill.');
        }
        $priced = $this->priceLines($lines);
        if (! $priced) {
            throw new BookingException('Count what is in the bag first.');
        }
        $r->loadMissing('guest', 'rooms.room');

        return DB::transaction(function () use ($r, $by, $priced, $express, $note) {
            $order = new LaundryOrder([
                'reservation_id' => $r->id,
                'room_number'    => $r->rooms->pluck('room.number')->filter()->implode(', ') ?: null,
                'guest_name'     => $r->guest?->full_name,
                'lines'          => $priced,
                'express'        => $express,
                'status'         => LaundryOrder::REQUESTED,
                'via'            => 'desk',
            ] + $this->totals($priced, $express));
            $order->save();
            $order->refresh();

            return $this->checkIn($order, $by, $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null);
        });
    }

    private function checkIn(LaundryOrder $order, User $by, ?string $note): LaundryOrder
    {
        $r = $order->reservation;
        if (! $r || $r->status !== ReservationStatus::CheckedIn) {
            throw new BookingException('The guest is no longer checked in. Call the room before taking the laundry.');
        }
        $now = now();
        $order->forceFill([
            'status' => LaundryOrder::COLLECTED, 'collected_by' => $by->id, 'handled_by' => $by->id, 'collected_at' => $now,
            'due_at' => $this->dueAt($now, $order->express), 'staff_note' => $note ?? $order->staff_note,
        ])->save();

        $desc = "Laundry {$order->number}: " . $order->summary . ($order->express ? ' — express' : '');
        $charge = $this->folios->postCharge($this->folios->forReservation($r), ChargeType::Laundry, mb_substr($desc, 0, 255), 1, (float) $order->total, today(),
            ['source_type' => LaundryOrder::class, 'source_id' => $order->id, 'idempotency_key' => "laundry:{$order->id}"]);
        $order->forceFill(['folio_charge_id' => $charge->id])->save();
        Audit::record('laundry.collected', "Collected laundry {$order->number} (room {$order->room_number}): {$order->pieces} pieces, " . $this->money((float) $order->total) . ' on the bill', $order, by: $by);

        return $order;
    }

    public function ready(LaundryOrder $order, User $by): void
    {
        $this->move($order, $by, [LaundryOrder::COLLECTED], LaundryOrder::READY, ['ready_at' => now()]);
    }

    public function returned(LaundryOrder $order, User $by): void
    {
        $this->move($order, $by, [LaundryOrder::COLLECTED, LaundryOrder::READY], LaundryOrder::RETURNED, ['returned_at' => now()]);
        $order->reservation?->log('laundry', "Laundry {$order->number} returned to the room.");
    }

    /** Turn it down before it's collected (nothing charged). The guest sees why. */
    public function decline(LaundryOrder $order, User $by, string $reason): void
    {
        $this->move($order, $by, [LaundryOrder::REQUESTED], LaundryOrder::DECLINED, ['staff_note' => mb_substr($reason, 0, 255)]);
        Audit::record('laundry.declined', "Declined laundry {$order->number}: {$reason}", $order, by: $by);
    }

    /** A damaged or missing piece. Recorded on the order and the booking; any refund is made on the bill. */
    public function reportIssue(LaundryOrder $order, User $by, string $issue): void
    {
        $this->authorize($by);
        if (in_array($order->status, [LaundryOrder::REQUESTED, LaundryOrder::DECLINED, LaundryOrder::CANCELLED], true)) {
            throw new BookingException('Problems can be recorded once the laundry has been collected.');
        }
        $issue = mb_substr(trim($issue), 0, 500);
        $order->forceFill(['issue' => trim(($order->issue ? $order->issue . ' | ' : '') . $issue)])->save();
        $order->reservation?->log('laundry', "Problem with laundry {$order->number}: {$issue}");
        Audit::record('laundry.issue', "Problem recorded on laundry {$order->number}: {$issue}", $order, by: $by);
    }

    private function move(LaundryOrder $order, User $by, array $from, string $to, array $extra = []): void
    {
        $this->authorize($by);
        $updated = LaundryOrder::whereKey($order->id)->whereIn('status', $from)
            ->update(['status' => $to, 'handled_by' => $by->id, 'updated_at' => now()] + $extra);
        if (! $updated) {
            throw new BookingException("{$order->number} has moved on. Refresh the list.");
        }
        $order->refresh();
    }

    private function authorize(User $by): void
    {
        if (! $this->canHandle($by)) {
            throw new AuthorizationException('You are not allowed to handle laundry.');
        }
    }

    // ── For the guest app and the booking page ────────────────────────

    /** The price list grouped for guests: [{name, items: [{id, name, services: [{id, label, price, price_label}]}]}] */
    public function priceList(): array
    {
        return $this->items()->groupBy('group')->map(fn ($list, $group) => [
            'name'  => $group,
            'items' => $list->map(fn (LaundryItem $i) => [
                'id'       => $i->id,
                'name'     => $i->name,
                'services' => collect($i->services())->map(fn ($p, $s) => ['id' => $s, 'label' => LaundryItem::serviceLabel($s), 'price' => $p, 'price_label' => $this->money($p)])->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }

    /** Everything the guest's laundry screen needs. */
    public function forGuestScreen(Reservation $r): array
    {
        $hours = $this->hoursLabel();

        return [
            'available'      => $this->canAsk($r),
            'currency'       => config('hotel.currency_symbol'),
            'blocker'        => $this->blocker($r),
            'hours'          => $hours,
            'collecting_now' => $this->collectingNow(),
            'note'           => trim((string) config('hotel.laundry.guest_note', '')) ?: null,
            'standard_hours' => $this->turnaroundHours(false),
            'express'        => ['percent' => $this->expressPercent(), 'hours' => $this->turnaroundHours(true)],
            'services'       => collect(LaundryItem::SERVICES)->map(fn ($v, $k) => ['id' => $k, 'label' => $v[0]])->values()->all(),
            'groups'         => $this->enabled() ? $this->priceList() : [],
            'orders'         => $this->forGuest($r),
        ];
    }

    /** The stay's laundry as the guest sees it, newest first. */
    public function forGuest(Reservation $r): array
    {
        return LaundryOrder::where('reservation_id', $r->id)->latest('id')->limit(10)->get()->map(fn (LaundryOrder $o) => [
            'id'           => $o->id,
            'number'       => $o->number,
            'summary'      => $o->lines ? $o->summary : 'To be counted when we collect it',
            'pieces'       => $o->pieces,
            'express'      => $o->express,
            'total_label'  => $o->lines ? $this->money((float) $o->total) : null,
            'status'       => $o->status,
            'status_label' => $o->status_label,
            'live'         => $o->isLive(),
            'note'         => $o->staff_note,
            'due'          => in_array($o->status, [LaundryOrder::COLLECTED, LaundryOrder::READY], true) && $o->due_at ? 'Back by ' . $this->when($o->due_at) : null,
            'can_cancel'   => $o->status === LaundryOrder::REQUESTED,
            'time'         => $o->created_at->format('D g:i A'),
        ])->all();
    }

    private function when(Carbon $t): string
    {
        return $t->isToday() ? $t->format('g:i A') . ' today' : ($t->isTomorrow() ? $t->format('g:i A') . ' tomorrow' : $t->format('D g:i A'));
    }

    // ── Set-up ────────────────────────────────────────────────────────

    /** A typical Nigerian hotel price list to start from (the hotel changes the prices). */
    public function seedDefaults(): int
    {
        if (LaundryItem::exists()) {
            return 0;
        }
        $rows = [
            // name, group, wash & iron, dry clean, press only
            ['Shirt', 'Tops', 1500, 2500, 800], ['Blouse', 'Tops', 1500, 2500, 800], ['T-shirt / polo', 'Tops', 1000, null, 500],
            ['Trousers', 'Bottoms', 1500, 2500, 800], ['Jeans', 'Bottoms', 1500, null, 800], ['Skirt', 'Bottoms', 1500, 2500, 800], ['Shorts', 'Bottoms', 1000, null, 500],
            ['Dress', 'Dresses & suits', 2000, 4000, 1200], ['Suit (2 pieces)', 'Dresses & suits', null, 6000, 2500], ['Jacket / blazer', 'Dresses & suits', null, 4500, 1500],
            ['Senator / kaftan', 'Native wear', 2000, 4000, 1200], ['Agbada (3 pieces)', 'Native wear', 3500, 7000, 2500], ['Buba & sokoto', 'Native wear', 2500, 4500, 1500],
            ['Underwear', 'Other', 500, null, null], ['Socks (pair)', 'Other', 400, null, null], ['Nightwear', 'Other', 1000, null, 500], ['Tie', 'Other', null, 1500, 600],
        ];
        foreach ($rows as $i => [$name, $group, $wash, $dry, $press]) {
            LaundryItem::create(['name' => $name, 'group' => $group, 'wash_price' => $wash, 'dry_price' => $dry, 'press_price' => $press, 'sort_order' => $i + 1]);
        }

        return count($rows);
    }

    private function money(float $v): string
    {
        return config('hotel.currency_symbol') . number_format($v, 2);
    }
}
