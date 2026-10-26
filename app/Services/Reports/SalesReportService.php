<?php

namespace App\Services\Reports;

use App\Enums\ReservationStatus;
use App\Models\Company;
use App\Models\CompanyLedgerEntry;
use App\Models\Events\Event;
use App\Models\Events\Hall;
use App\Models\NightAudit;
use App\Models\Reservation;
use App\Services\Companies\CompanyLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sales reports: how rooms were sold (rate plans, promo codes, where bookings came from), company
 * accounts, events, and the daily figures saved by each night audit.
 */
class SalesReportService
{
    /** Stays arriving in the period, by rate plan and by booking source. Cancellations and no-shows counted apart. */
    public function rates(Carbon $from, Carbon $to): array
    {
        $all = Reservation::with('rooms')->where('check_in_date', '>=', $from->toDateString())->where('check_in_date', '<=', $to->toDateString() . ' 23:59:59')->get();
        $lost = [ReservationStatus::Cancelled, ReservationStatus::NoShow];
        $kept = $all->reject(fn ($r) => in_array($r->status, $lost, true));
        $nights = fn ($r) => $r->nights * max(1, $r->rooms->count());

        $group = fn (Collection $rows, callable $key) => $rows->groupBy($key)->map(function ($g, $label) use ($nights, $all, $key, $lost) {
            $roomNights = $g->sum($nights);
            $revenue = round((float) $g->sum('total_amount'), 2);
            $lostHere = $all->filter(fn ($r) => $key($r) === $label && in_array($r->status, $lost, true))->count();

            return ['label' => $label, 'bookings' => $g->count(), 'nights' => $roomNights, 'revenue' => $revenue,
                'avg' => $roomNights ? round($revenue / $roomNights, 2) : 0, 'lost' => $lostHere];
        })->sortByDesc('revenue')->values();

        $byPlan = $group($kept, fn ($r) => $r->rate_plan_name ?: 'Standard rate');
        $byPromo = $group($kept->filter(fn ($r) => $r->promo_code), fn ($r) => $r->promo_code);
        $bySource = $group($kept, fn ($r) => $r->source->label());
        // Online travel agencies one by one (Booking.com, Expedia, Airbnb ...).
        $byChannel = $group($kept->filter(fn ($r) => $r->channel), fn ($r) => $r->channel);

        return [
            'by_plan' => $byPlan, 'by_promo' => $byPromo, 'by_source' => $bySource, 'by_channel' => $byChannel,
            'totals' => ['bookings' => $kept->count(), 'nights' => $kept->sum($nights), 'revenue' => round((float) $kept->sum('total_amount'), 2),
                'lost' => $all->count() - $kept->count(), 'discount' => round((float) $kept->sum('discount_amount'), 2)],
        ];
    }

    /** Each company: stays in the period, billed, paid, what it owes now and how late. */
    public function companies(Carbon $from, Carbon $to): array
    {
        $ledger = app(CompanyLedger::class);
        $f = $from->toDateString();
        $t = $to->toDateString() . ' 23:59:59';
        $rows = Company::orderBy('name')->get()->map(function (Company $c) use ($ledger, $f, $t) {
            $entries = CompanyLedgerEntry::whereNull('voided_at')->where('company_id', $c->id)->where('entry_date', '>=', $f)->where('entry_date', '<=', $t);
            $stays = Reservation::where('company_id', $c->id)->where('check_in_date', '>=', $f)->where('check_in_date', '<=', $t)
                ->whereNotIn('status', [ReservationStatus::Cancelled->value, ReservationStatus::NoShow->value])->with('rooms')->get();
            $age = $ledger->ageing($c);

            return [
                'company' => $c, 'stays' => $stays->count(), 'nights' => $stays->sum(fn ($r) => $r->nights * max(1, $r->rooms->count())),
                'events' => Event::where('company_id', $c->id)->whereIn('status', ['confirmed', 'completed'])->where('starts_at', '>=', $f)->where('starts_at', '<=', $t)->count(),
                'billed' => round((float) (clone $entries)->where('type', 'invoice')->sum('amount'), 2),
                'paid' => round(-1 * (float) (clone $entries)->where('type', 'payment')->sum('amount'), 2),
                'owes' => $c->balance(), 'overdue' => $age['overdue'], 'limit' => (float) $c->credit_limit,
            ];
        })->filter(fn ($r) => $r['stays'] || $r['events'] || $r['billed'] || $r['paid'] || abs($r['owes']) >= 0.01)->sortByDesc('billed')->values();

        return ['rows' => $rows, 'totals' => [
            'billed' => round($rows->sum('billed'), 2), 'paid' => round($rows->sum('paid'), 2),
            'owes' => round($rows->sum('owes'), 2), 'overdue' => round($rows->sum('overdue'), 2), 'nights' => $rows->sum('nights'),
        ]];
    }

    /** Events in the period: revenue, how busy each hall was, kinds of event, and how many enquiries became bookings. */
    public function events(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->addDay()->startOfDay();
        $events = Event::with(['hall', 'package'])->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->get();
        $sold = $events->whereIn('status', ['confirmed', 'completed']);
        $days = $start->diffInDays($end);

        // Hall use: hours booked out of 12 sellable hours a day.
        $halls = Hall::ordered()->get()->map(function (Hall $h) use ($sold, $days) {
            $mine = $sold->where('hall_id', $h->id);
            $hours = round($mine->sum(fn ($e) => $e->starts_at->diffInMinutes($e->ends_at) / 60), 1);

            return ['hall' => $h, 'events' => $mine->count(), 'hours' => $hours, 'use' => $days ? round(min(100, $hours / ($days * 12) * 100), 1) : 0, 'revenue' => round((float) $mine->sum('total'), 2)];
        });

        $created = Event::where('created_at', '>=', $start)->where('created_at', '<', $end)->get();

        return [
            'halls' => $halls,
            'by_type' => $sold->groupBy('type_label')->map(fn ($g, $l) => ['label' => $l, 'events' => $g->count(), 'guests' => $g->sum('guests_expected'), 'revenue' => round((float) $g->sum('total'), 2)])->sortByDesc('revenue')->values(),
            'list' => $events->sortBy('starts_at')->values(),
            'totals' => [
                'events' => $sold->count(), 'revenue' => round((float) $sold->sum('total'), 2), 'guests' => $sold->sum('guests_expected'),
                'cancelled' => $events->where('status', 'cancelled')->count(),
                'enquiries' => $created->count(), 'converted' => $created->whereIn('status', ['confirmed', 'completed'])->count(),
                'per_guest' => $sold->sum('guests_expected') ? round((float) $sold->sum('total') / $sold->sum('guests_expected'), 2) : 0,
            ],
        ];
    }

    /** The days closed by the night audit in the period, with averages and totals. */
    public function daily(Carbon $from, Carbon $to): array
    {
        $days = NightAudit::where('business_date', '>=', $from->toDateString())->where('business_date', '<=', $to->toDateString() . ' 23:59:59')
            ->orderBy('business_date')->get();
        $r = fn ($k) => $days->map(fn ($d) => data_get($d->report, $k, 0));
        $sold = $r('rooms.sold')->sum();
        $sellable = $r('rooms.sellable')->sum();
        $roomRevenue = $r('rooms.revenue')->sum();

        return [
            'days' => $days,
            'totals' => [
                'days' => $days->count(),
                'occupancy' => $sellable ? round($sold / $sellable * 100, 1) : 0,
                'adr' => $sold ? round($roomRevenue / $sold, 2) : 0,
                'revpar' => $sellable ? round($roomRevenue / $sellable, 2) : 0,
                'room_revenue' => round($roomRevenue, 2),
                'revenue' => round($r('revenue.total')->sum(), 2),
                'collected' => round($r('collections.total')->sum(), 2),
                'no_shows' => $r('guests.no_shows')->sum(),
            ],
            'missing' => max(0, $from->diffInDays($to->copy()->min(today())) + 1 - $days->count()),
        ];
    }
}
