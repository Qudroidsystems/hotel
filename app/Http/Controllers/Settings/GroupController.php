<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NightAudit;
use App\Models\Reservation;
use App\Services\Staff\StaffAppService;
use App\Support\Tenancy\CurrentHotel;

/** Group overview (multi-hotel): today and this month, hotel by hotel, for owners and group managers. */
class GroupController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Access all hotels');
    }

    public function index(StaffAppService $app)
    {
        abort_unless(CurrentHotel::multi(), 404);
        $rows = self::rows(request()->user(), $app);

        $totals = [
            'rooms' => $rows->sum('today.rooms'), 'occupied' => $rows->sum('today.occupied'), 'in_house' => $rows->sum('today.in_house'),
            'arrivals' => $rows->sum('today.arrivals'), 'departures' => $rows->sum('today.departures'),
            'collected' => $rows->sum('today.collected'), 'month_revenue' => $rows->sum('month_revenue'),
        ];

        return view('group.index', ['rows' => $rows, 'totals' => $totals, 'pagetitle' => 'Group overview']);
    }

    /** Today and this month for each hotel this person sees (also the staff app's group screen). */
    public static function rows(\App\Models\User $user, StaffAppService $app): \Illuminate\Support\Collection
    {
        return $user->accessibleHotels()->map(fn ($hotel) => CurrentHotel::get()->run($hotel, function () use ($hotel, $app) {
            $t = $app->today();
            $month = NightAudit::where('business_date', '>=', today()->startOfMonth()->toDateString())->get();

            return [
                'hotel'       => $hotel,
                'currency'    => config('hotel.currency_symbol'),
                'today'       => $t,
                'month_revenue' => round($month->sum(fn ($a) => (float) data_get($a->report, 'revenue.total', 0)), 2),
                'month_occupancy' => ($s = $month->sum(fn ($a) => (int) data_get($a->report, 'rooms.sellable', 0))) ? round($month->sum(fn ($a) => (int) data_get($a->report, 'rooms.sold', 0)) / $s * 100, 1) : null,
                'days_closed' => $month->count(),
                'upcoming'    => Reservation::whereIn('status', ['pending', 'confirmed'])->where('check_in_date', '>', today()->toDateString())
                    ->where('check_in_date', '<=', today()->addDays(7)->toDateString() . ' 23:59:59')->count(),
                'last_audit'  => NightAudit::latest('business_date')->value('business_date'),
            ];
        }));
    }

    /**
     * Commission: for a month, what each hotel owes the group on stays booked through the group site that
     * ended (checked out) that month, what it has paid, and what is still coming (booked, not stayed yet).
     */
    public function commission(\Illuminate\Http\Request $request)
    {
        abort_unless(CurrentHotel::multi(), 404);
        $month = $this->month($request->query('month'));
        $period = $month->format('Y-m');
        $hotels = request()->user()->accessibleHotels();

        $rows = CurrentHotel::get()->across(fn () => $hotels->map(function ($hotel) use ($month, $period) {
            $stays = Reservation::where('hotel_id', $hotel->id)->where('via_group', true)->where('status', 'checked_out')
                ->where('check_out_date', '>=', $month->toDateString())->where('check_out_date', '<=', $month->copy()->endOfMonth()->toDateString() . ' 23:59:59')->get();
            $coming = Reservation::where('hotel_id', $hotel->id)->where('via_group', true)->whereIn('status', ['pending', 'confirmed', 'checked_in'])->get();
            $paid = (float) \App\Models\CommissionPayment::where('hotel_id', $hotel->id)->where('period', $period)->sum('amount');
            $due = round((float) $stays->sum('commission_amount'), 2);

            return ['hotel' => $hotel, 'stays' => $stays->count(), 'value' => round((float) $stays->sum('total_amount'), 2), 'due' => $due,
                'paid' => round($paid, 2), 'balance' => round($due - $paid, 2), 'rate' => $hotel->commissionRate(),
                'coming' => $coming->count(), 'coming_value' => round((float) $coming->sum('commission_amount'), 2)];
        }));
        $payments = \App\Models\CommissionPayment::with(['hotel', 'recorder'])->where('period', $period)->latest('received_on')->get();

        return view('group.commission', compact('rows', 'month', 'payments') + ['pagetitle' => 'Commission']);
    }

    /** One hotel's statement for a month: every stay and its commission (printable). */
    public function statement(\Illuminate\Http\Request $request, \App\Models\Hotel $hotel)
    {
        abort_unless(CurrentHotel::multi() && request()->user()->canWorkAt($hotel->id), 404);
        $month = $this->month($request->query('month'));
        $stays = CurrentHotel::get()->across(fn () => Reservation::with('guest')->where('hotel_id', $hotel->id)->where('via_group', true)->where('status', 'checked_out')
            ->where('check_out_date', '>=', $month->toDateString())->where('check_out_date', '<=', $month->copy()->endOfMonth()->toDateString() . ' 23:59:59')
            ->orderBy('check_out_date')->get());
        $paid = \App\Models\CommissionPayment::where('hotel_id', $hotel->id)->where('period', $month->format('Y-m'))->get();

        return view('group.statement', compact('hotel', 'month', 'stays', 'paid') + ['pagetitle' => 'Commission statement']);
    }

    public function recordPayment(\Illuminate\Http\Request $request)
    {
        abort_unless(CurrentHotel::multi(), 404);
        $data = $request->validate([
            'hotel_id' => 'required|integer|exists:hotels,id', 'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'amount' => 'required|numeric|min:0.01|max:1000000000', 'received_on' => 'required|date|before_or_equal:today',
            'reference' => 'nullable|string|max:100', 'note' => 'nullable|string|max:255',
        ]);
        abort_unless($request->user()->canWorkAt((int) $data['hotel_id']), 403);
        $p = \App\Models\CommissionPayment::create($data + ['recorded_by' => $request->user()->id]);
        \App\Support\Audit::record('group.commission_paid', 'Commission received from ' . $p->hotel->name . ' for ' . $data['period'] . ': ' . number_format($p->amount, 2));

        return back()->with('success', 'Payment recorded.');
    }

    /**
     * Group reports: each hotel side by side for a period — the night audit's figures (occupancy, average
     * rate, RevPAR, revenue, money taken) and where its bookings came from. CSV with ?export=csv.
     */
    public function reports(\Illuminate\Http\Request $request, \App\Services\Reports\SalesReportService $sales)
    {
        abort_unless(CurrentHotel::multi(), 404);
        try {
            $from = \Illuminate\Support\Carbon::parse($request->query('from', today()->startOfMonth()->toDateString()))->startOfDay();
            $to = \Illuminate\Support\Carbon::parse($request->query('to', today()->toDateString()))->startOfDay();
        } catch (\Throwable) {
            [$from, $to] = [today()->startOfMonth(), today()];
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $to = $to->min($from->copy()->addYear());

        $rows = request()->user()->accessibleHotels()->map(fn ($hotel) => CurrentHotel::get()->run($hotel, function () use ($hotel, $sales, $from, $to) {
            $daily = $sales->daily($from, $to);
            $d = $daily['totals'] + ['missing' => $daily['missing']];
            $r = $sales->rates($from, $to);
            $events = $sales->events($from, $to)['totals'];

            return [
                'hotel' => $hotel, 'currency' => config('hotel.currency_symbol'), 'daily' => $d,
                'bookings' => $r['totals']['bookings'], 'nights' => $r['totals']['nights'], 'booked_value' => $r['totals']['revenue'], 'lost' => $r['totals']['lost'],
                'sources' => collect($r['by_source'])->mapWithKeys(fn ($x) => [$x['label'] => $x['revenue']])->all(),
                'events' => $events['events'], 'events_revenue' => $events['revenue'],
                'group_site' => (float) \App\Models\Reservation::where('via_group', true)->whereNotIn('status', ['cancelled', 'no_show'])
                    ->where('check_in_date', '>=', $from->toDateString())->where('check_in_date', '<=', $to->toDateString() . ' 23:59:59')->sum('total_amount'),
            ];
        }));
        $sources = $rows->flatMap(fn ($r) => array_keys($r['sources']))->unique()->sort()->values();

        if ($request->query('export') === 'csv') {
            $head = ['Hotel', 'Days closed', 'Occupancy %', 'Average rate', 'RevPAR', 'Room revenue', 'Total revenue', 'Money taken', 'No-shows',
                'Stays booked', 'Room nights', 'Booked value', 'Cancelled / no-show', 'Events', 'Event revenue', 'Via group site', ...$sources->map(fn ($s) => 'Source: ' . $s)];
            $lines = $rows->map(fn ($r) => [$r['hotel']->name, $r['daily']['days'], $r['daily']['occupancy'], $r['daily']['adr'], $r['daily']['revpar'], $r['daily']['room_revenue'],
                $r['daily']['revenue'], $r['daily']['collected'], $r['daily']['no_shows'], $r['bookings'], $r['nights'], $r['booked_value'], $r['lost'], $r['events'], $r['events_revenue'],
                $r['group_site'], ...$sources->map(fn ($s) => $r['sources'][$s] ?? 0)]);

            return response()->streamDownload(function () use ($head, $lines) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $head);
                foreach ($lines as $l) {
                    fputcsv($out, $l);
                }
                fclose($out);
            }, 'group-report-' . $from->toDateString() . '-to-' . $to->toDateString() . '.csv', ['Content-Type' => 'text/csv']);
        }

        return view('group.reports', compact('rows', 'from', 'to', 'sources') + ['pagetitle' => 'Group reports']);
    }

    private function month(?string $m): \Illuminate\Support\Carbon
    {
        try {
            return $m && preg_match('/^\d{4}-\d{2}$/', $m) ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $m . '-01')->startOfDay() : today()->startOfMonth();
        } catch (\Throwable) {
            return today()->startOfMonth();
        }
    }
}
