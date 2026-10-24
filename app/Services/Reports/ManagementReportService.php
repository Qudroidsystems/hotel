<?php

namespace App\Services\Reports;

use App\Enums\BookingSource;
use App\Enums\ChargeType;
use App\Enums\PaymentKind;
use App\Enums\ReservationStatus;
use App\Models\Folio;
use App\Models\FolioCharge;
use App\Models\Payment;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosOrderItem;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Support\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Owner / manager figures for a date range (spec §13). Revenue, money
 * collected and money still owed are kept apart on purpose (spec §9) — they
 * are different measures.
 *
 * Occupancy is counted from posted room nights: every night a room is sold
 * gets exactly one "room" charge on the guest bill, so the bills are the
 * single source for both room revenue and rooms sold.
 */
class ManagementReportService
{
    public function build(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $days = collect(CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()))->map(fn ($d) => $d->toDateString());
        $rooms = Room::active()->count();

        // Guest-bill charges in the period, except bar sales charged to rooms (counted under POS below).
        $charges = FolioCharge::whereNull('voided_at')
            ->whereBetween('service_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', PosOrder::class))
            ->get(['type', 'total', 'tax_breakdown', 'service_date']);

        $posOrders = PosOrder::with('outlet:id,name')->whereIn('status', [PosOrder::PAID, PosOrder::CHARGED])
            ->whereBetween('ordered_at', [$start, $end])
            ->get(['id', 'outlet_id', 'status', 'method', 'total', 'tax_amount', 'tax_breakdown', 'discount_amount', 'ordered_at']);

        $payments = Payment::valid()->whereBetween('received_at', [$start, $end])->get(['kind', 'method', 'amount', 'received_at']);

        $roomCharges = $charges->filter(fn ($c) => $c->type === ChargeType::Room);
        $sold = $roomCharges->count();
        $available = $rooms * $days->count();
        $roomRevenue = (float) $roomCharges->sum('total');

        // ── Day by day ─────────────────────────────────────────────────
        $roomByDay = $roomCharges->groupBy(fn ($c) => $c->service_date->toDateString());
        $posByDay = $posOrders->groupBy(fn ($o) => $o->ordered_at->toDateString());
        $payByDay = $payments->groupBy(fn ($p) => $p->received_at->toDateString());
        $daily = $days->map(function ($d) use ($roomByDay, $posByDay, $payByDay, $posOrders, $rooms) {
            $r = $roomByDay[$d] ?? collect();
            $pos = $posByDay[$d] ?? collect();
            $paid = $payByDay[$d] ?? collect();

            return [
                'date'       => Carbon::parse($d),
                'sold'       => $r->count(),
                'occupancy'  => $rooms ? round($r->count() / $rooms * 100, 1) : 0,
                'room'       => (float) $r->sum('total'),
                'pos'        => (float) $pos->sum('total'),
                'collected'  => (float) $paid->sum(fn ($p) => $p->signed_amount) + (float) $pos->where('status', PosOrder::PAID)->sum('total'),
            ];
        })->values();

        // ── Revenue by department ──────────────────────────────────────
        $departments = $charges->groupBy(fn ($c) => $c->type->value)
            ->map(fn ($g, $type) => ['label' => ChargeType::from($type)->label(), 'source' => 'Guest bills', 'amount' => (float) $g->sum('total'), 'tax' => $this->tax($g), 'count' => $g->count()])
            ->sortByDesc('amount');
        $outlets = $posOrders->groupBy('outlet_id')
            ->map(fn ($g) => ['label' => $g->first()->outlet?->name ?? 'Outlet', 'source' => 'Bar & restaurant', 'amount' => (float) $g->sum('total'), 'tax' => $this->tax($g), 'count' => $g->count(),
                'room' => (float) $g->where('status', PosOrder::CHARGED)->sum('total')])
            ->sortByDesc('amount');
        $revenue = (float) $departments->sum('amount') + (float) $outlets->sum('amount');
        $tax = (float) $departments->sum('tax') + (float) $outlets->sum('tax');

        // ── Money: earned vs collected vs owed ─────────────────────────
        $collectedBills = (float) $payments->sum(fn ($p) => $p->signed_amount);
        $collectedPos = (float) $posOrders->where('status', PosOrder::PAID)->sum('total');
        $openFolios = Folio::where('status', 'open')->pluck('id')->all();
        $balances = collect(Folio::balances($openFolios));

        // ── Reservations ──────────────────────────────────────────────
        $arrivals = Reservation::whereBetween('check_in_date', [$from->toDateString(), $to->toDateString()])->get(['id', 'status', 'source', 'check_in_date', 'check_out_date', 'total_amount']);
        $stayed = $arrivals->whereIn('status', [ReservationStatus::CheckedIn, ReservationStatus::CheckedOut]);
        $bySource = $arrivals->groupBy(fn ($r) => $r->source instanceof BookingSource ? $r->source->value : (string) $r->source)
            ->map(fn ($g, $src) => ['label' => BookingSource::tryFrom($src)?->label() ?? ucfirst($src), 'count' => $g->count(),
                'stayed' => $g->whereIn('status', [ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])->count(),
                'value' => (float) $g->whereNotIn('status', [ReservationStatus::Cancelled, ReservationStatus::NoShow])->sum('total_amount')])
            ->sortByDesc('count');

        // ── Best sellers ──────────────────────────────────────────────
        $topItems = PosOrderItem::whereIn('order_id', $posOrders->pluck('id'))
            ->selectRaw('name, sum(quantity) as qty, sum(total) as amount')
            ->groupBy('name')->orderByDesc('amount')->limit(10)->get();

        return [
            'days'        => $days->count(),
            'rooms'       => $rooms,
            'kpi'         => [
                'available'    => $available,
                'sold'         => $sold,
                'occupancy'    => $available ? round($sold / $available * 100, 1) : 0,
                'room_revenue' => $roomRevenue,
                'adr'          => $sold ? round($roomRevenue / $sold, 2) : 0,
                'revpar'       => $available ? round($roomRevenue / $available, 2) : 0,
                'pos'          => (float) $posOrders->sum('total'),
                'revenue'      => $revenue,
                'tax'          => $tax,
            ],
            'daily'       => $daily,
            'departments' => $departments->values(),
            'outlets'     => $outlets->values(),
            'money'       => [
                'revenue'        => $revenue,
                'collected'      => $collectedBills + $collectedPos,
                'collected_bills' => $collectedBills,
                'collected_pos'  => $collectedPos,
                'refunds'        => (float) $payments->where('kind', PaymentKind::Refund)->sum('amount'),
                'owed_now'       => (float) $balances->sum(fn ($b) => max(0, $b['balance'])),
                'credit_now'     => (float) $balances->sum(fn ($b) => max(0, -$b['balance'])),
            ],
            'bookings'    => [
                'arrivals'     => $arrivals->count(),
                'stayed'       => $stayed->count(),
                'cancelled'    => $arrivals->where('status', ReservationStatus::Cancelled)->count(),
                'no_show'      => $arrivals->where('status', ReservationStatus::NoShow)->count(),
                'avg_nights'   => $stayed->count() ? round($stayed->avg(fn ($r) => $r->check_in_date->diffInDays($r->check_out_date)), 1) : 0,
                'by_source'    => $bySource->values(),
            ],
            'top_items'   => $topItems,
        ];
    }

    /** The same figures for the period just before, for "vs previous" comparisons. */
    public function previous(Carbon $from, Carbon $to): array
    {
        $len = $from->diffInDays($to) + 1;

        return $this->build($from->copy()->subDays($len), $from->copy()->subDay());
    }

    /** Tax included in a set of charges or sales, from their snapshots. */
    private function tax(Collection $rows): float
    {
        return (float) $rows->sum(fn ($r) => collect($r->tax_breakdown ?? [])->sum('amount'));
    }
}
