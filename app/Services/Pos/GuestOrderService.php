<?php

namespace App\Services\Pos;

use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Pos\GuestOrder;
use App\Models\Pos\PosCategory;
use App\Models\Pos\PosItem;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosOutlet;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guests order food and drinks to their room from the guest app or the QR
 * card in the room. The order waits for the outlet: accepting it rings it up
 * as a POS sale charged to the room (the same path as a till), so the bill,
 * the stock and the sales reports all follow. Then "on the way" and
 * "delivered" let the guest follow it.
 */
class GuestOrderService
{
    public const MAX_WAITING = 3;      // orders a room can have waiting for the kitchen at once
    public const MAX_LINES = 30;
    public const MAX_QUANTITY = 20;

    public function __construct(private PosService $pos)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.booking.guest_ordering', true);
    }

    /** Outlets that take guests' orders (open now or not), in menu order. */
    public function outlets(): Collection
    {
        return PosOutlet::where('guest_orders', true)->where('is_active', true)->where('allow_room_charge', true)->ordered()->get();
    }

    /** Can this stay order now? Answers the reason when not (null = yes). */
    public function blocker(Reservation $r): ?string
    {
        if (! $this->enabled() || $this->outlets()->isEmpty()) {
            return 'Ordering to your room isn’t available at this hotel.';
        }
        if ($r->status !== ReservationStatus::CheckedIn) {
            return 'You can order once you’ve checked in.';
        }
        if ($this->outlets()->doesntContain(fn (PosOutlet $o) => $o->openForGuests())) {
            return 'The kitchen and bar aren’t taking room orders right now.';
        }

        return null;
    }

    public function canOrder(Reservation $r): bool
    {
        return $this->blocker($r) === null;
    }

    /**
     * The menu for guests: each outlet that takes room orders, with its
     * categories and items. Closed outlets are listed with their hours.
     */
    public function menu(): array
    {
        $sym = config('hotel.currency_symbol');

        return $this->outlets()->map(function (PosOutlet $o) use ($sym) {
            $items = $o->items()->where('pos_items.is_active', true)->whereHas('category', fn ($q) => $q->where('is_active', true))
                ->with('category:id,name,sort_order')->orderBy('pos_items.sort_order')->orderBy('pos_items.name')->get();

            return [
                'id'          => $o->id,
                'name'        => $o->name,
                'open'        => $o->openForGuests(),
                'hours'       => $o->guest_hours_label,
                'minutes'     => $o->delivery_minutes,
                'categories'  => $items->groupBy('category_id')
                    ->sortBy(fn ($list) => [$list->first()->category?->sort_order ?? 0, $list->first()->category?->name])
                    ->map(fn ($list) => [
                        'name'  => $list->first()->category?->name ?? 'Other',
                        'items' => $list->map(fn (PosItem $i) => [
                            'id' => $i->id, 'name' => $i->name, 'description' => $i->description,
                            'price' => (float) $i->price, 'price_label' => $sym . number_format((float) $i->price, 2),
                        ])->values(),
                    ])->values(),
            ];
        })->filter(fn ($o) => collect($o['categories'])->isNotEmpty())->values()->all();
    }

    /**
     * A guest places an order: [{item_id, quantity, note}] from one outlet.
     * Prices are the menu's now, never what the phone sent.
     */
    public function place(Reservation $r, int $outletId, array $lines, ?string $note = null, string $via = 'app'): GuestOrder
    {
        if ($why = $this->blocker($r)) {
            throw new BookingException($why);
        }
        $outlet = $this->outlets()->firstWhere('id', $outletId);
        if (! $outlet) {
            throw new BookingException('That menu isn’t available. Pull down to refresh.');
        }
        if (! $outlet->openForGuests()) {
            throw new BookingException("{$outlet->name} takes room orders " . ($outlet->guest_hours_label ?? 'at other times') . '.');
        }
        $lines = array_values(array_filter($lines, fn ($l) => is_array($l) && (int) ($l['quantity'] ?? 0) > 0));
        if (! $lines) {
            throw new BookingException('Add something to your order first.');
        }
        if (count($lines) > self::MAX_LINES) {
            throw new BookingException('That’s a big order — please call the restaurant.');
        }
        $waiting = GuestOrder::where('reservation_id', $r->id)->where('status', GuestOrder::PLACED)->count();
        if ($waiting >= self::MAX_WAITING) {
            throw new BookingException('You have orders waiting for the kitchen. You can order more once they’re accepted.');
        }

        $menu = $outlet->items()->where('pos_items.is_active', true)->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->whereIn('pos_items.id', array_map(fn ($l) => (int) ($l['item_id'] ?? 0), $lines))->get()->keyBy('id');
        $priced = [];
        foreach ($lines as $l) {
            $item = $menu->get((int) ($l['item_id'] ?? 0));
            if (! $item) {
                throw new BookingException('Something in your order is no longer on the menu. Pull down to refresh.');
            }
            $priced[] = [
                'item_id'     => $item->id,
                'name'        => $item->name,
                'charge_type' => $item->charge_type->value,
                'quantity'    => min(self::MAX_QUANTITY, max(1, (int) $l['quantity'])),
                'unit_price'  => (float) $item->price,
                'note'        => isset($l['note']) && trim((string) $l['note']) !== '' ? mb_substr(trim((string) $l['note']), 0, 120) : null,
            ];
        }
        $price = PosService::price($priced);
        $r->loadMissing('guest', 'rooms.room');

        $order = GuestOrder::create([
            'uuid'           => (string) Str::uuid(),
            'reservation_id' => $r->id,
            'outlet_id'      => $outlet->id,
            'room_number'    => $r->rooms->pluck('room.number')->filter()->implode(', ') ?: null,
            'guest_name'     => $r->guest?->full_name,
            'lines'          => $priced,
            'note'           => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null,
            'total'          => $price['total'],
            'status'         => GuestOrder::PLACED,
            'via'            => in_array($via, ['app', 'web', 'room'], true) ? $via : 'app',
        ]);
        $r->log('guest_order', "Room order {$order->number} sent to {$outlet->name}: {$order->summary}.");

        return $order->fresh();
    }

    // ── Staff ─────────────────────────────────────────────────────────

    public function canHandle(User $user): bool
    {
        return $user->can('Use POS') && $user->can('Charge to room');
    }

    /** Accept: ring it up as a sale on the guest's bill. */
    public function accept(GuestOrder $order, User $by, ?string $note = null): void
    {
        $this->authorize($by);
        DB::transaction(function () use ($order, $by, $note) {
            $order = GuestOrder::whereKey($order->id)->lockForUpdate()->first();
            if ($order->status !== GuestOrder::PLACED) {
                throw new BookingException("{$order->number} has already been dealt with.");
            }
            $r = $order->reservation;
            if (! $r || $r->status !== ReservationStatus::CheckedIn) {
                throw new BookingException('The guest is no longer checked in. Decline the order and call the room.');
            }
            [$result] = $this->pos->sync([[
                'uuid'           => $order->uuid,
                'number'         => $order->number,
                'outlet_id'      => $order->outlet_id,
                'ordered_at'     => now()->toIso8601String(),
                'method'         => PosOrder::ROOM,
                'reservation_id' => $r->id,
                'room_number'    => $order->room_number,
                'guest_name'     => $order->guest_name,
                'table_label'    => 'Room service',
                'cashier_id'     => $by->id,
                'device_id'      => 'GUEST',
                'total'          => (float) $order->total,
                'items'          => array_map(fn ($l) => array_intersect_key($l, array_flip(['item_id', 'name', 'charge_type', 'quantity', 'unit_price', 'note'])), $order->lines),
            ]], $by);
            if ($result['result'] === 'rejected') {
                throw new BookingException($result['message'] ?? 'The sale could not be saved.');
            }
            $order->forceFill([
                'status' => GuestOrder::ACCEPTED, 'pos_order_id' => $result['id'], 'handled_by' => $by->id, 'accepted_at' => now(),
                'staff_note' => $note ? mb_substr($note, 0, 255) : $order->staff_note,
            ])->save();
            Audit::record('pos.guest_order_accepted', "Accepted room order {$order->number} (room {$order->room_number}) — {$result['number']} on the bill", $order, by: $by);
        });
    }

    public function send(GuestOrder $order, User $by): void
    {
        $this->move($order, $by, [GuestOrder::ACCEPTED], GuestOrder::ON_THE_WAY, ['sent_at' => now()]);
    }

    public function deliver(GuestOrder $order, User $by): void
    {
        $this->move($order, $by, [GuestOrder::ACCEPTED, GuestOrder::ON_THE_WAY], GuestOrder::DELIVERED, ['delivered_at' => now()]);
    }

    /** Turn it down before it's made (nothing was charged). The reason is shown to the guest. */
    public function decline(GuestOrder $order, User $by, string $reason): void
    {
        $this->move($order, $by, [GuestOrder::PLACED], GuestOrder::DECLINED, ['staff_note' => mb_substr($reason, 0, 255)]);
        Audit::record('pos.guest_order_declined', "Declined room order {$order->number}: {$reason}", $order, by: $by);
    }

    /** The guest changes their mind while it's still waiting. */
    public function cancelByGuest(GuestOrder $order): void
    {
        $updated = GuestOrder::whereKey($order->id)->where('status', GuestOrder::PLACED)->update(['status' => GuestOrder::CANCELLED, 'updated_at' => now()]);
        if (! $updated) {
            throw new BookingException('The kitchen has already started on it — call them if you need to change it.');
        }
    }

    private function move(GuestOrder $order, User $by, array $from, string $to, array $extra = []): void
    {
        $this->authorize($by);
        $updated = GuestOrder::whereKey($order->id)->whereIn('status', $from)
            ->update(['status' => $to, 'handled_by' => $by->id, 'updated_at' => now()] + $extra);
        if (! $updated) {
            throw new BookingException("{$order->number} has moved on. Refresh the list.");
        }
        $order->refresh();
    }

    private function authorize(User $by): void
    {
        if (! $this->canHandle($by)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You are not allowed to handle room orders.');
        }
    }

    // ── For the guest ─────────────────────────────────────────────────

    /** The stay's orders as the guest sees them, newest first. */
    public function forGuest(Reservation $r): array
    {
        $sym = config('hotel.currency_symbol');

        return GuestOrder::where('reservation_id', $r->id)->with('outlet:id,name,delivery_minutes')->latest('id')->limit(15)->get()
            ->map(fn (GuestOrder $o) => [
                'id'           => $o->id,
                'number'       => $o->number,
                'outlet'       => $o->outlet?->name,
                'summary'      => $o->summary,
                'total_label'  => $sym . number_format((float) $o->total, 2),
                'status'       => $o->status,
                'status_label' => $o->status_label,
                'live'         => $o->isLive(),
                'note'         => in_array($o->status, [GuestOrder::DECLINED, GuestOrder::ACCEPTED, GuestOrder::ON_THE_WAY], true) ? $o->staff_note : null,
                'eta'          => $o->status === GuestOrder::ACCEPTED && $o->outlet?->delivery_minutes && $o->accepted_at
                    ? 'About ' . $o->accepted_at->copy()->addMinutes($o->outlet->delivery_minutes)->format('g:i A') : null,
                'can_cancel'   => $o->status === GuestOrder::PLACED,
                'time'         => $o->created_at->format('D g:i A'),
            ])->all();
    }
}
