<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Pos\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Hotel overview. For now it summarises accounts and access control; each
 * new module (rooms, reservations, POS, inventory…) adds its own tiles here.
 */
class DashboardController extends Controller
{
    public function index()
    {
        // Staff without the dashboard permission land on their own profile.
        if (! auth()->user()->can('dashboard')) {
            return redirect()->route('users.overview', auth()->id());
        }

        $pagetitle = 'Hotel Overview';

        $stats = [
            'users'       => User::count(),
            'active'      => User::where('is_disabled', false)->count(),
            'roles'       => Role::count(),
            'permissions' => Permission::count(),
        ];

        $usersByRole = Role::withCount('users')->orderByDesc('users_count')->get(['id', 'name']);

        $recentLogins = User::whereNotNull('last_login_at')
            ->with('roles')
            ->orderByDesc('last_login_at')
            ->limit(8)
            ->get();

        $newestUsers = User::with('roles')->latest()->limit(5)->get();

        // Rooms: count per status across active rooms.
        $roomCounts = null;
        if (auth()->user()->can('View rooms')) {
            $byStatus = Room::active()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
            $roomCounts = [
                'total'     => $byStatus->sum(),
                'by_status' => collect(RoomStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            ];
        }

        $frontDesk = null;
        if (auth()->user()->can('View reservations')) {
            $frontDesk = [
                'arrivals'   => Reservation::arrivingOn(today())->with('guest', 'rooms.room', 'rooms.roomType')->orderBy('expected_arrival_time')->get(),
                'departures' => Reservation::departingOn(today())->count(),
                'inhouse'    => Reservation::inHouse()->count(),
                'upcoming'   => Reservation::open()->whereBetween('check_in_date', [today()->addDay()->toDateString(), today()->addDays(7)->toDateString()])->count(),
            ];
        }

        // Money: today's takings (own only for cashiers) and what is still owed.
        $money = null;
        $user = auth()->user();
        if ($user->canAny(['View finance reports', 'Receive payments', 'View folios'])) {
            $today = Payment::valid()->whereBetween('received_at', [today()->startOfDay(), today()->endOfDay()])
                ->unless($user->can('View finance reports'), fn ($q) => $q->where('received_by', $user->id))
                ->get(['kind', 'amount']);
            $posToday = PosOrder::takings()->whereBetween('ordered_at', [today()->startOfDay(), today()->endOfDay()])
                ->unless($user->can('View finance reports'), fn ($q) => $q->where('cashier_id', $user->id))
                ->get(['total']);
            $openIds = Folio::where('status', 'open')->pluck('id')->all();
            $balances = collect(Folio::balances($openIds));
            $money = [
                'mine'      => ! $user->can('View finance reports'),
                'collected' => $today->sum(fn ($p) => $p->signed_amount) + (float) $posToday->sum('total'),
                'count'     => $today->count() + $posToday->count(),
                'pos'       => (float) $posToday->sum('total'),
                'owed'      => $balances->sum(fn ($b) => max(0, $b['balance'])),
                'owing'     => $balances->filter(fn ($b) => $b['balance'] >= 0.01)->count(),
                'credit'    => $balances->sum(fn ($b) => max(0, -$b['balance'])),
            ];
        }

        // Bar & restaurant: today's sales and anything waiting for a supervisor.
        $bar = null;
        if ($user->can('View POS reports')) {
            $day = PosOrder::whereBetween('ordered_at', [today()->startOfDay(), today()->endOfDay()])->where('status', '!=', PosOrder::VOIDED)->get(['status', 'total']);
            $bar = [
                'sales'     => (float) $day->sum('total'),
                'count'     => $day->count(),
                'room'      => (float) $day->where('status', PosOrder::CHARGED)->sum('total'),
                'attention' => PosOrder::where('status', PosOrder::ATTENTION)->count(),
            ];
        }

        // Stores: value and what needs reordering.
        $stock = null;
        if ($user->can('View stock')) {
            $items = \App\Models\Inventory\StockItem::active()->with('levels')->get();
            $stock = [
                'value'    => app(\App\Services\Inventory\StockService::class)->stockValue(),
                'low'      => $items->filter(fn ($i) => $i->isLow())->count(),
                'negative' => $items->filter(fn ($i) => $i->levels->contains(fn ($l) => $l->quantity < 0))->count(),
                'due'      => \App\Models\Inventory\PurchaseOrder::whereIn('status', ['ordered', 'partial'])->count(),
            ];
        }

        // Staff attendance today.
        $staffToday = null;
        if ($user->can('View attendance')) {
            $board = app(\App\Services\Attendance\AttendanceService::class)->board(today()->toDateString());
            $staffToday = [
                'total'  => $board->count(),
                'in'     => $board->where('is_in', true)->count(),
                'late'   => $board->filter(fn ($r) => $r['late'] > 0)->count(),
                'due'    => $board->where('status', 'due')->count(),
                'absent' => $board->where('status', 'absent')->count(),
                'leave'  => $board->where('status', 'leave')->count(),
            ];
        }

        return view('dashboards.dashboard', compact('staffToday', 'stock', 'bar', 'pagetitle', 'stats', 'usersByRole', 'recentLogins', 'newestUsers', 'roomCounts', 'frontDesk', 'money'));
    }
}
