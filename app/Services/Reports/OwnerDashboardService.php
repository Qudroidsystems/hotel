<?php

namespace App\Services\Reports;

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestRequest;
use App\Models\Finance\Expense;
use App\Models\Hotel;
use App\Models\Inventory\PurchaseOrder;
use App\Models\It\ItAlert;
use App\Models\NightAudit;
use App\Models\Pos\GuestOrder;
use App\Models\Pos\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Staff\StaffAppService;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The owner's phone screen: how the hotel (or the whole group) is doing —
 * today, the last 14 days against the 14 before, the month so far against
 * the same days last month, the week ahead on the books, and what needs a
 * decision. Built on the management report, so the numbers match the
 * website's reports.
 */
class OwnerDashboardService
{
    public const DAYS = 14;

    public function __construct(private ManagementReportService $report, private StaffAppService $staff)
    {
    }

    public function build(User $user, bool $group = false): array
    {
        $group = $group && CurrentHotel::multi() && $user->can('Access all hotels');
        $hotels = $group ? $user->accessibleHotels()->where('is_active', true)->values() : collect([CurrentHotel::get()->hotel()])->filter();
        $packs = $hotels->mapWithKeys(fn (Hotel $h) => [$h->id => CurrentHotel::get()->run($h, fn () => $this->pack())]);
        if ($packs->isEmpty()) {
            $packs = collect([0 => $this->pack()]);
        }

        $sum = fn (string $path) => (float) $packs->sum(fn ($p) => data_get($p, $path, 0));
        $sym = config('hotel.currency_symbol');
        $money = fn ($v) => $sym . number_format((float) $v, 0);
        $ratio = fn ($a, $b) => $b > 0 ? round($a / $b * 100, 1) : 0.0;
        $delta = fn ($now, $before) => $before > 0 ? round(($now - $before) / $before * 100, 1) : null;

        // The last 14 days against the 14 before.
        $k = fn ($which) => [
            'revenue'   => $sum("$which.revenue"),
            'room'      => $sum("$which.room_revenue"),
            'sold'      => $sum("$which.sold"),
            'available' => $sum("$which.available"),
            'collected' => $sum("$which.collected"),
        ];
        [$now, $before] = [$k('kpi'), $k('prev')];
        $occ = $ratio($now['sold'], $now['available']);
        $occBefore = $ratio($before['sold'], $before['available']);
        $adr = $now['sold'] ? $now['room'] / $now['sold'] : 0;
        $adrBefore = $before['sold'] ? $before['room'] / $before['sold'] : 0;
        $revpar = $now['available'] ? $now['room'] / $now['available'] : 0;
        $revparBefore = $before['available'] ? $before['room'] / $before['available'] : 0;

        $headline = [
            ['key' => 'revenue', 'label' => 'Revenue', 'value' => $now['revenue'], 'value_label' => $money($now['revenue']), 'delta' => $delta($now['revenue'], $before['revenue']), 'hint' => 'Rooms, food & drink and other sales'],
            ['key' => 'occupancy', 'label' => 'Occupancy', 'value' => $occ, 'value_label' => $occ . '%', 'delta' => $occBefore || $occ ? round($occ - $occBefore, 1) : null, 'delta_unit' => 'pts', 'hint' => (int) $now['sold'] . ' room nights sold'],
            ['key' => 'adr', 'label' => 'Average rate', 'value' => round($adr, 2), 'value_label' => $money($adr), 'delta' => $delta($adr, $adrBefore), 'hint' => 'Per room night sold'],
            ['key' => 'revpar', 'label' => 'RevPAR', 'value' => round($revpar, 2), 'value_label' => $money($revpar), 'delta' => $delta($revpar, $revparBefore), 'hint' => 'Room revenue per room available'],
            ['key' => 'collected', 'label' => 'Money taken', 'value' => $now['collected'], 'value_label' => $money($now['collected']), 'delta' => $delta($now['collected'], $before['collected']), 'hint' => 'Payments in, refunds out'],
            ['key' => 'owed', 'label' => 'Owed on open bills', 'value' => $sum('owed'), 'value_label' => $money($sum('owed')), 'delta' => null, 'hint' => 'Guests in house and unsettled bills'],
        ];

        // Day by day (dates line up across hotels).
        $daily = collect($packs->first()['daily'])->keys()->map(function ($date) use ($packs, $ratio) {
            $d = fn ($k) => (float) $packs->sum(fn ($p) => $p['daily'][$date][$k] ?? 0);
            $rooms = (float) $packs->sum('rooms');

            return [
                'date' => $date, 'label' => Carbon::parse($date)->format('D j'),
                'revenue' => round($d('room') + $d('pos'), 2), 'collected' => round($d('collected'), 2),
                'occupancy' => $ratio($d('sold'), $rooms),
            ];
        })->values();

        $ahead = collect($packs->first()['ahead'])->keys()->map(function ($date) use ($packs, $ratio) {
            $sold = (int) $packs->sum(fn ($p) => $p['ahead'][$date] ?? 0);
            $rooms = (int) $packs->sum('rooms');

            return ['date' => $date, 'label' => Carbon::parse($date)->format('D j'), 'sold' => $sold, 'rooms' => $rooms, 'occupancy' => $ratio($sold, $rooms)];
        })->values();

        $t = fn ($k) => (int) $packs->sum(fn ($p) => $p['today'][$k] ?? 0);
        $today = [
            'rooms' => $t('rooms'), 'occupied' => $t('occupied'), 'occupancy' => $ratio($t('occupied'), $t('rooms')),
            'in_house' => $t('in_house'), 'arrivals' => $t('arrivals'), 'arrived' => $t('arrived'), 'departures' => $t('departures'),
            'collected_label' => $money($packs->sum(fn ($p) => $p['today']['collected'] ?? 0)),
            'pos_label' => $money($packs->sum(fn ($p) => $p['today']['pos_sales'] ?? 0)),
        ];

        $mNow = $sum('month.revenue');
        $mBefore = $sum('month_prev.revenue');
        $mOcc = $ratio($sum('month.sold'), $sum('month.available'));
        $month = [
            'label' => today()->format('F') . ' so far', 'compare' => 'vs the same days of ' . today()->subMonthNoOverflow()->format('F'),
            'revenue_label' => $money($mNow), 'revenue_delta' => $delta($mNow, $mBefore),
            'occupancy' => $mOcc, 'occupancy_delta' => round($mOcc - $ratio($sum('month_prev.sold'), $sum('month_prev.available')), 1),
            'collected_label' => $money($sum('month.collected')),
        ];

        $alerts = $packs->flatMap(fn ($p, $hid) => collect($p['alerts'])->map(fn ($a) => $a + ['hotel' => $group ? $hotels->firstWhere('id', $hid)?->name : null]))
            ->sortBy(fn ($a) => ['danger' => 0, 'warning' => 1, 'info' => 2][$a['level']] ?? 3)->values();

        return [
            'scope'      => $group ? 'group' : 'hotel',
            'title'      => $group ? (config('hotel.group_name') ?: 'All hotels') : (CurrentHotel::get()->hotel()?->name ?? config('hotel.name')),
            'can_group'  => CurrentHotel::multi() && $user->can('Access all hotels'),
            'period'     => ['days' => self::DAYS, 'label' => 'Last ' . self::DAYS . ' days', 'compare' => 'vs the ' . self::DAYS . ' days before'],
            'headline'   => $headline,
            'daily'      => $daily,
            'today'      => $today,
            'month'      => $month,
            'ahead'      => $ahead,
            'alerts'     => $alerts,
            'hotels'     => $group ? $hotels->map(fn (Hotel $h) => [
                'id' => $h->id, 'name' => $h->name, 'city' => $h->city,
                'occupancy_today' => $ratio($packs[$h->id]['today']['occupied'] ?? 0, $packs[$h->id]['today']['rooms'] ?? 0),
                'revenue' => (float) $packs[$h->id]['kpi']['revenue'], 'revenue_label' => $money($packs[$h->id]['kpi']['revenue']),
                'occupancy' => $ratio($packs[$h->id]['kpi']['sold'], $packs[$h->id]['kpi']['available']),
                'alerts' => count($packs[$h->id]['alerts']),
            ])->sortByDesc('revenue')->values() : [],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** The current hotel's raw figures. */
    private function pack(): array
    {
        $to = today();
        $from = $to->copy()->subDays(self::DAYS - 1);
        $now = $this->report->build($from, $to);
        $prev = $this->report->previous($from, $to);
        $monthStart = $to->copy()->startOfMonth();
        $month = $this->report->build($monthStart, $to);
        $lastStart = $monthStart->copy()->subMonthNoOverflow();
        $lastEnd = $lastStart->copy()->addDays($monthStart->diffInDays($to))->min($lastStart->copy()->endOfMonth());
        $monthPrev = $this->report->build($lastStart, $lastEnd);
        $rooms = Room::active()->count();

        $k = fn ($r) => [
            'revenue' => $r['kpi']['revenue'], 'room_revenue' => $r['kpi']['room_revenue'], 'sold' => $r['kpi']['sold'],
            'available' => $r['kpi']['available'], 'collected' => $r['money']['collected'],
        ];

        return [
            'rooms'      => $rooms,
            'kpi'        => $k($now),
            'prev'       => $k($prev),
            'month'      => $k($month),
            'month_prev' => $k($monthPrev),
            'owed'       => $now['money']['owed_now'],
            'daily'      => collect($now['daily'])->mapWithKeys(fn ($d) => [$d['date']->toDateString() => $d])->all(),
            'ahead'      => $this->ahead(),
            'today'      => $this->staff->today(),
            'alerts'     => $this->alerts(),
        ];
    }

    /** Rooms on the books for each of the next 7 nights. */
    private function ahead(): array
    {
        $out = [];
        $stays = Reservation::whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value, ReservationStatus::CheckedIn->value])
            ->where('check_out_date', '>', today()->toDateString())->where('check_in_date', '<=', today()->addDays(7)->toDateString())
            ->withCount('rooms')->get(['id', 'check_in_date', 'check_out_date']);
        foreach (range(0, 6) as $i) {
            $night = today()->addDays($i);
            $out[$night->toDateString()] = (int) $stays->filter(fn ($r) => $r->check_in_date->lte($night) && $r->check_out_date->gt($night))->sum('rooms_count');
        }

        return $out;
    }

    /** What needs the owner's attention, worst first. */
    private function alerts(): array
    {
        $a = collect();
        $add = function (string $level, string $text, ?string $module = null) use ($a) {
            $a->push(compact('level', 'text', 'module'));
        };

        $lastClosed = NightAudit::where('status', 'closed')->max('business_date');
        $behind = $lastClosed ? Carbon::parse($lastClosed)->diffInDays(today()->subDay(), false) : null;
        if ($behind === null && Reservation::exists()) {
            $add('warning', 'No business day has been closed with the night audit yet.', 'night_audit');
        } elseif ($behind !== null && $behind >= 1) {
            $add($behind >= 3 ? 'danger' : 'warning', (int) $behind . ' business day' . ($behind == 1 ? '' : 's') . ' not closed with the night audit.', 'night_audit');
        }
        if ($n = PosOrder::where('status', PosOrder::ATTENTION)->count()) {
            $add('danger', "{$n} bar/restaurant sale" . ($n === 1 ? ' needs' : 's need') . ' sorting out (room charges that didn’t reach a bill).');
        }
        $owing = Reservation::where('status', ReservationStatus::CheckedOut->value)->whereHas('folio', fn ($q) => $q->where('status', 'open'))->count();
        if ($owing) {
            $add('warning', "{$owing} guest" . ($owing === 1 ? ' has' : 's have') . ' checked out with an unpaid bill.');
        }
        if ($n = Room::active()->whereIn('status', ['maintenance', 'out_of_service'])->count()) {
            $add('info', "{$n} room" . ($n === 1 ? ' is' : 's are') . ' out of order.', 'rooms');
        }
        $waiting = Expense::where('status', 'pending')->count() + PurchaseOrder::where('status', 'pending')->count();
        if ($waiting) {
            $add('info', "{$waiting} expense" . ($waiting === 1 ? '' : 's') . ' or purchase order' . ($waiting === 1 ? '' : 's') . ' waiting for approval.', 'approvals');
        }
        if ($n = GuestOrder::where('status', GuestOrder::PLACED)->where('created_at', '<', now()->subMinutes(10))->count()) {
            $add('warning', "{$n} room order" . ($n === 1 ? ' has' : 's have') . ' waited over 10 minutes for the kitchen.', 'work');
        }
        if ($n = GuestRequest::active()->where('created_at', '<', now()->subMinutes(30))->count()) {
            $add('warning', "{$n} guest request" . ($n === 1 ? ' is' : 's are') . ' still open after 30 minutes.', 'work');
        }
        if ($n = ItAlert::where('status', 'open')->whereIn('severity', ['critical', 'high'])->count()) {
            $add('danger', "{$n} system" . ($n === 1 ? ' is' : 's are') . ' down or failing (IT).');
        }

        return $a->values()->all();
    }
}
