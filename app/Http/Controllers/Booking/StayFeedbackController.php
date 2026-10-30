<?php

namespace App\Http\Controllers\Booking;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking\GuestFeedback;
use App\Models\Reservation;
use App\Services\Booking\FeedbackService;
use Illuminate\Http\Request;

/** The guest's survey after check-out, opened from their booking page or the link we text them. */
class StayFeedbackController extends Controller
{
    public function __construct(private FeedbackService $feedback)
    {
    }

    public function show(string $token)
    {
        $r = $this->reservation($token);
        $r->loadMissing('guest');

        return view('booking.feedback', ['r' => $r, 'fb' => $this->feedback->forGuest($r), 'blocker' => $this->feedback->blocker($r)]);
    }

    public function store(Request $request, string $token)
    {
        $r = $this->reservation($token);
        $data = $request->validate(self::rules());
        try {
            $this->feedback->submit($r, $data, 'web');
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stay.feedback', $token)->with('success', 'Thank you! Your feedback has been sent to our managers.');
    }

    /** Shared with the guest app's API. */
    public static function rules(): array
    {
        $aspects = array_keys(GuestFeedback::ASPECTS);

        return [
            'overall'    => 'required|integer|min:1|max:5',
            'ratings'    => 'nullable|array',
            'ratings.*'  => 'nullable|integer|min:1|max:5',
            'recommend'  => 'nullable|integer|min:0|max:10',
            'comment'    => 'nullable|string|max:' . FeedbackService::MAX_COMMENT,
            'contact_ok' => 'nullable|boolean',
            'may_quote'  => 'nullable|boolean',
        ] + collect($aspects)->mapWithKeys(fn ($a) => ["ratings.{$a}" => 'nullable|integer|min:1|max:5'])->all();
    }

    private function reservation(string $token): Reservation
    {
        abort_unless(strlen($token) === 48, 404);

        $r = Reservation::withoutGlobalScope('hotel')->where('guest_token', $token)->firstOrFail();
        \App\Support\Tenancy\CurrentHotel::get()->adopt($r);

        return $r;
    }
}
