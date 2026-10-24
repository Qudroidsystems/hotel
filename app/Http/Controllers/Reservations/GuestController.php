<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use Illuminate\Http\Request;

/** Guest directory and profiles (spec §12.5 guest relationship management). */
class GuestController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View guests', ['only' => ['index', 'show', 'search']]);
        $this->middleware('permission:Manage guests', ['only' => ['edit', 'update', 'destroy']]);
    }

    public function index(Request $request)
    {
        $pagetitle = 'Guests';
        $guests = Guest::search($request->query('q'))
            ->when($request->boolean('vip'), fn ($q) => $q->where('is_vip', true))
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
        ]));
    }

    public function show(Guest $guest)
    {
        $pagetitle = $guest->full_name;
        $reservations = $guest->reservations()->with('rooms.room', 'rooms.roomType')->paginate(10);

        return view('guests.show', compact('pagetitle', 'guest', 'reservations'));
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
