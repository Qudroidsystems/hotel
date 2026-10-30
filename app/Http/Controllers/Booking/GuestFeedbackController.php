<?php

namespace App\Http\Controllers\Booking;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking\GuestFeedback;
use App\Services\Booking\FeedbackService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Front Desk → Guest Feedback: scores after check-out, and following up low ones. */
class GuestFeedbackController extends Controller
{
    public function __construct(private FeedbackService $feedback)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['View guest feedback', 'Handle guest feedback']), 403);

        $period = in_array($request->query('period'), ['7', '30', '90', '365'], true) ? (int) $request->query('period') : 30;
        $from = today()->subDays($period - 1);
        $show = array_key_exists((string) $request->query('show'), GuestFeedback::STATUSES) ? $request->query('show') : null;
        $stars = in_array($request->query('stars'), ['low', 'high'], true) ? $request->query('stars') : null;
        $low = (int) config('hotel.feedback.alert_below', 2);

        $rows = GuestFeedback::with(['guest:id,first_name,last_name', 'reservation:id,reference,check_in_date,check_out_date', 'handler:id,name'])
            ->where('created_at', '>=', $from)
            ->when($show, fn ($q) => $q->where('status', $show))
            ->when($stars === 'low', fn ($q) => $q->where('overall', '<=', $low))
            ->when($stars === 'high', fn ($q) => $q->where('overall', '>=', 4))
            ->when($request->boolean('comments'), fn ($q) => $q->whereNotNull('comment'))
            ->orderByRaw("case status when 'follow_up' then 0 else 1 end")
            ->latest()
            ->paginate(30)->withQueryString();

        $summary = $this->feedback->summary($from, today());
        $previous = $this->feedback->summary($from->copy()->subDays($period), $from->copy()->subDay());
        $pagetitle = 'Guest Feedback';

        return view('feedback.index', compact('pagetitle', 'rows', 'summary', 'previous', 'period', 'show', 'stars'));
    }

    public function show(Request $request, GuestFeedback $feedback)
    {
        abort_unless($request->user()->canAny(['View guest feedback', 'Handle guest feedback']), 403);
        $feedback->load(['guest', 'reservation.rooms.room', 'handler']);
        $earlier = $feedback->guest_id
            ? GuestFeedback::with('reservation:id,reference,check_in_date')->where('guest_id', $feedback->guest_id)->whereKeyNot($feedback->id)->latest()->limit(5)->get()
            : collect();
        $pagetitle = 'Feedback · ' . ($feedback->reservation?->reference ?? '#' . $feedback->id);

        return view('feedback.show', ['pagetitle' => $pagetitle, 'f' => $feedback, 'earlier' => $earlier]);
    }

    public function handle(Request $request, GuestFeedback $feedback)
    {
        abort_unless($request->user()->can('Handle guest feedback'), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(GuestFeedback::STATUSES))],
            'note'   => 'nullable|string|max:2000',
        ]);
        try {
            $this->feedback->handle($feedback, $request->user(), $data['status'], $data['note'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Saved.');
    }

    public function publish(Request $request, GuestFeedback $feedback)
    {
        abort_unless($request->user()->can('Handle guest feedback') && $request->user()->can('Manage website'), 403);
        try {
            $this->feedback->publish($feedback, $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The comment is now on the website with the guest reviews (Website → Guest reviews to change or hide it).');
    }
}
