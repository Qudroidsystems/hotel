<?php

namespace App\Http\Controllers\Booking;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking\GuestRequest;
use App\Services\Booking\GuestRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff side of guest requests: the queue, status updates, "send to maintenance". */
class GuestRequestController extends Controller
{
    public function __construct(private GuestRequestService $service)
    {
        $this->middleware('permission:Handle guest requests');
    }

    public function index(Request $request)
    {
        $show = in_array($request->query('show'), ['active', 'done', 'all'], true) ? $request->query('show') : 'active';
        $requests = GuestRequest::with(['reservation.guest:id,title,first_name,last_name,is_vip', 'room:id,number', 'housekeepingTask', 'maintenanceOrder', 'handler:id,name'])
            ->when($show === 'active', fn ($q) => $q->active())
            ->when($show === 'done', fn ($q) => $q->whereIn('status', ['done', 'cancelled']))
            ->latest()->paginate(40)->withQueryString();
        $this->service->refresh($requests->getCollection());
        $today = GuestRequest::where('created_at', '>=', today())->get(['status', 'created_at', 'handled_at']);
        $handled = $today->whereNotNull('handled_at');
        $stats = [
            'open'    => GuestRequest::where('status', 'open')->count(),
            'working' => GuestRequest::where('status', 'in_progress')->count(),
            'today'   => $today->count(),
            'minutes' => $handled->isNotEmpty() ? (int) round($handled->avg(fn ($r) => $r->created_at->diffInMinutes($r->handled_at))) : null,
        ];
        $pagetitle = 'Guest Requests';

        return view('booking.staff.requests', compact('pagetitle', 'requests', 'show', 'stats'));
    }

    public function update(Request $request, GuestRequest $guestRequest)
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(GuestRequest::STATUSES))], 'staff_note' => 'nullable|string|max:500']);
        $this->service->update($guestRequest, $request->user(), $data['status'], $data['staff_note'] ?? null);

        return back()->with('success', 'Request updated — the guest sees it on their booking page.');
    }

    public function toMaintenance(Request $request, GuestRequest $guestRequest)
    {
        abort_unless($request->user()->canAny(['Report maintenance issues', 'Manage maintenance']), 403);
        try {
            $order = $this->service->toMaintenance($guestRequest, $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$order->number} raised for maintenance.");
    }
}
