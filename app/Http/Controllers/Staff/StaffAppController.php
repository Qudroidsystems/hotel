<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\MaintenanceOrder;
use App\Models\Room;
use App\Services\Staff\StaffAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Staff App (spec §16): a phone-first, installable view of the system for
 * staff who don't work at a desk. Every button posts to the same routes as
 * the full site, so permissions, approvals and the audit trail are unchanged.
 */
class StaffAppController extends Controller
{
    public function __construct(private StaffAppService $app)
    {
    }

    public function home(Request $request)
    {
        $user = $request->user();
        $work = $this->app->work($user);
        $approvals = $this->app->approvals($user);
        $isManager = $user->canAny(['View management reports', 'View finance reports', 'Manage hotel settings']);
        $today = $isManager || $user->canAny(['Check in guests', 'Manage housekeeping']) ? $this->app->today() : null;
        $attendance = $this->app->attendance($user);
        $myTickets = \App\Models\It\ItTicket::active()->where('requested_by', $user->id)->latest()->limit(3)->get();
        $pagetitle = 'Today';

        return view('staff.home', compact('pagetitle', 'work', 'approvals', 'today', 'isManager', 'attendance', 'myTickets'));
    }

    public function work(Request $request)
    {
        $work = $this->app->work($request->user());
        $pagetitle = 'My Work';

        return view('staff.work', compact('pagetitle', 'work'));
    }

    public function approvals(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canAny(['Approve expenses', 'Approve purchases', 'Review cash shifts', 'Post stock counts']), 403);
        $items = $this->app->approvals($user);
        $pagetitle = 'Approvals';

        return view('staff.approvals', compact('pagetitle', 'items'));
    }

    public function report(Request $request)
    {
        $rooms = Room::active()->orderBy('number')->get(['id', 'number']);
        $pagetitle = 'Report';

        return view('staff.report', compact('pagetitle', 'rooms'));
    }

    public function frontDesk(Request $request)
    {
        abort_unless($request->user()->canAny(['View reservations', 'Check in guests']), 403);
        $desk = $this->app->frontDesk();
        $today = $this->app->today();
        $pagetitle = 'Front Desk';

        return view('staff.front-desk', compact('pagetitle', 'desk', 'today'));
    }

    /** Find a stock item and see what's on hand in each store. */
    public function stock(Request $request)
    {
        abort_unless($request->user()->can('View stock'), 403);
        $q = trim((string) $request->query('q'));
        $items = collect();
        if (mb_strlen($q) >= 2) {
            $items = StockItem::active()->with('category:id,name')
                ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('sku', $q))
                ->orderBy('name')->limit(25)->get();
            $levels = DB::table('stock_levels')->whereIn('item_id', $items->pluck('id'))->get()->groupBy('item_id');
            $items->each(fn ($i) => $i->setAttribute('by_store', ($levels[$i->id] ?? collect())->pluck('quantity', 'location_id')));
        }
        $locations = StockLocation::ordered()->get(['id', 'name']);
        $pagetitle = 'Stock';

        return view('staff.stock', compact('pagetitle', 'q', 'items', 'locations'));
    }

    public function more(Request $request)
    {
        $pagetitle = 'More';

        return view('staff.more', compact('pagetitle'));
    }

    /** Web app manifest so the Staff App can be added to a phone's home screen. */
    public function manifest()
    {
        $base = rtrim(parse_url(route('staff.home'), PHP_URL_PATH) ?: '/staff', '/');   // keeps a sub-folder install (e.g. /hotel/public) intact

        return response()->json([
            'name'             => config('hotel.name') . ' Staff',
            'short_name'       => 'Staff',
            'description'      => 'Work, approvals and quick reports for ' . config('hotel.name') . ' staff',
            'start_url'        => $base . '/?source=app',
            'scope'            => $base . '/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#0f172a',
            'theme_color'      => '#0f766e',
            'icons'            => [
                ['src' => asset('staff-app/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('staff-app/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('staff-app/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
