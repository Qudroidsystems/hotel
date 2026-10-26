<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Exceptions\BookingException;
use App\Models\Folio;
use App\Models\Guest;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\Request;

/** Guest directory and profiles (spec §12.5 guest relationship management). */
class GuestController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View guests', ['only' => ['index', 'show', 'search']]);
        $this->middleware('permission:Manage guests', ['only' => ['edit', 'update', 'destroy', 'adjustPoints']]);
        $this->middleware('permission:Receive payments', ['only' => ['redeemPoints']]);
    }

    public function index(Request $request)
    {
        $pagetitle = 'Guests';
        $guests = Guest::search($request->query('q'))
            ->when($request->boolean('vip'), fn ($q) => $q->where('is_vip', true))
            ->when(isset(\App\Services\Loyalty\LoyaltyService::TIERS[$request->query('tier')]), fn ($q) => $q->where('loyalty_tier', $request->query('tier')))
            ->withCount('reservations')
            ->withMax('reservations', 'check_in_date')
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(25)->withQueryString();

        return view('guests.index', compact('pagetitle', 'guests'));
    }

    /** JSON for the booking form's guest picker. */
    public function search(Request $request)
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json(Guest::search($term)->withCount('reservations')->limit(8)->get()->map(fn (Guest $g) => [
            'id'      => $g->id,
            'name'    => $g->full_name,
            'phone'   => $g->phone,
            'email'   => $g->email,
            'company' => $g->company,
            'vip'     => $g->is_vip,
            'stays'   => $g->reservations_count,
            'tier'    => $g->loyalty_tier !== 'member' ? $g->tier_label : null,
            'points'  => $g->loyalty_points,
        ]));
    }

    public function show(Guest $guest, LoyaltyService $loyalty)
    {
        $pagetitle = $guest->full_name;
        $reservations = $guest->reservations()->with('rooms.room', 'rooms.roomType')->paginate(10);
        $stats = $loyalty->stats($guest);
        $entries = $loyalty->history($guest);
        $groupStays = $loyalty->groupStays($guest);
        $next = $loyalty->enabled() ? $loyalty->nextTier($guest) : null;
        $nights12 = $loyalty->recentNights($guest);

        return view('guests.show', compact('pagetitle', 'guest', 'reservations', 'stats', 'entries', 'next', 'nights12', 'loyalty', 'groupStays'));
    }

    /** Add or take away points by hand, with a reason (goodwill, correction ...). */
    public function adjustPoints(Request $request, Guest $guest, LoyaltyService $loyalty)
    {
        $data = $request->validate([
            'direction' => 'required|in:add,remove',
            'points'    => 'required|integer|min:1|max:10000000',
            'reason'    => 'required|string|max:255',
        ]);
        try {
            $loyalty->adjust($guest, $data['direction'] === 'add' ? (int) $data['points'] : -(int) $data['points'], $data['reason'], $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Points ' . ($data['direction'] === 'add' ? 'added.' : 'taken away.'));
    }

    /** Spend a guest's points on their open bill. */
    public function redeemPoints(Request $request, Folio $folio, LoyaltyService $loyalty)
    {
        $data = $request->validate(['points' => 'required|integer|min:1|max:10000000']);
        try {
            $charge = $loyalty->redeem($folio, (int) $data['points'], $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', number_format((int) $data['points']) . ' points used: ' . config('hotel.currency_symbol') . number_format(abs((float) $charge->total), 2) . ' off the bill.');
    }

    public function edit(Guest $guest)
    {
        return view('guests.form', ['pagetitle' => 'Edit ' . $guest->full_name, 'guest' => $guest, 'idTypes' => Guest::ID_TYPES]);
    }

    public function update(Request $request, Guest $guest)
    {
        $data = $request->validate([
            'title'         => 'nullable|string|max:20',
            'first_name'    => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
            'email'         => 'nullable|email|max:255',
            'phone'         => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'gender'        => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date|before:today',
            'nationality'   => 'nullable|string|max:100',
            'id_type'       => 'nullable|string|max:50',
            'id_number'     => 'nullable|string|max:100',
            'address'       => 'nullable|string|max:500',
            'company'       => 'nullable|string|max:150',
            'preferences'   => 'nullable|string|max:2000',
            'notes'         => 'nullable|string|max:2000',
        ]);
        $data['is_vip'] = $request->boolean('is_vip');
        $data['messages_opt_out'] = $request->boolean('messages_opt_out');
        $data['marketing_opt_in'] = $request->boolean('marketing_opt_in');

        $guest->update($data);

        return redirect()->route('guests.show', $guest)->with('success', 'Guest profile updated.');
    }

    public function destroy(Guest $guest)
    {
        if ($guest->reservations()->exists()) {
            return back()->with('error', 'This guest has reservations and can’t be deleted.');
        }

        $guest->delete();

        return redirect()->route('guests.index')->with('success', 'Guest deleted.');
    }
}
