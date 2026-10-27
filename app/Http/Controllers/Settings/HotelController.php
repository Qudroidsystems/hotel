<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Audit;
use App\Support\HotelSettings;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Hotels: run one hotel or a group of hotels from this system; add hotels; choose which staff
 * work at each. Staff with "Access all hotels" (owners, group managers) see every hotel.
 */
class HotelController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage hotels', ['except' => ['switch']]);
    }

    public function index(Request $request)
    {
        $hotels = Hotel::ordered()->withCount('users')->get();
        $edit = $request->query('hotel') ? $hotels->firstWhere('id', (int) $request->query('hotel')) : null;
        $users = User::where('is_disabled', false)->orderBy('name')->get(['id', 'name', 'email']);
        $assigned = $edit ? $edit->users()->pluck('users.id')->all() : [];
        $counts = CurrentHotel::get()->across(fn () => [
            'rooms' => \App\Models\Room::selectRaw('hotel_id, count(*) n')->groupBy('hotel_id')->pluck('n', 'hotel_id'),
            'bookings' => \App\Models\Reservation::selectRaw('hotel_id, count(*) n')->whereIn('status', ['pending', 'confirmed', 'checked_in'])->groupBy('hotel_id')->pluck('n', 'hotel_id'),
        ]);

        return view('settings.hotels', [
            'pagetitle' => 'Hotels', 'hotels' => $hotels, 'edit' => $edit, 'users' => $users, 'assigned' => $assigned,
            'counts' => $counts, 'multi' => CurrentHotel::multi(), 'creating' => $request->boolean('new'),
        ]);
    }

    /** Single hotel or a group of hotels. */
    public function mode(Request $request, \App\Services\Loyalty\LoyaltyService $loyalty)
    {
        $multi = $request->boolean('multi');
        $request->validate(['group_name' => 'nullable|string|max:100', 'commission_percent' => 'nullable|numeric|min:0|max:50']);
        HotelSettings::putGroup('multi_hotel', $multi, $request->user()->id);
        if ($request->has('commission_percent')) {
            HotelSettings::putGroup('commission_percent', round((float) $request->input('commission_percent'), 2), $request->user()->id);
        }
        if ($request->has('group_name')) {
            HotelSettings::putGroup('group_name', trim((string) $request->input('group_name')) ?: null, $request->user()->id);
        }
        if ($request->has('group_loyalty')) {
            HotelSettings::putGroup('group_loyalty', $request->boolean('group_loyalty'), $request->user()->id);
        }
        Audit::record('settings.hotel_mode', 'System set to ' . ($multi ? 'several hotels' : 'one hotel'));
        HotelSettings::applyFor(\App\Support\Tenancy\CurrentHotel::get()->id());
        $joined = $multi ? $loyalty->linkAll() : 0;

        return back()->with('success', ($multi
            ? 'The system now runs several hotels. Add hotels below and choose who works at each; staff switch hotel from the top bar.'
            : 'The system now runs one hotel. Other hotels and their data are kept but hidden.')
            . ($joined ? " {$joined} guest" . ($joined === 1 ? ' was' : 's were') . ' a member at more than one hotel; their points are now together.' : ''));
    }

    public function store(Request $request)
    {
        $hotel = Hotel::create($this->data($request));
        $hotel->users()->syncWithoutDetaching([$request->user()->id]);
        Audit::record('settings.hotels', "Hotel added: {$hotel->name}", new: $hotel->only(['name', 'city']));

        return redirect()->route('settings.hotels.index', ['hotel' => $hotel->id])->with('success', "{$hotel->name} added. Choose its staff, then switch to it from the top bar to set up its rooms, rates and settings.");
    }

    public function update(Request $request, Hotel $hotel)
    {
        $data = $this->data($request, $hotel);
        if (! $data['is_active'] && Hotel::active()->where('id', '!=', $hotel->id)->doesntExist()) {
            return back()->with('error', 'At least one hotel must stay open.');
        }
        $hotel->update($data);
        // Keep Hotel Settings → Branding's name in step with the hotel's name here.
        CurrentHotel::get()->run($hotel, function () use ($hotel) {
            \App\Models\HotelSetting::where('key', 'name')->update(['value' => json_encode($hotel->name)]);
        });
        if ($request->has('staff')) {
            $hotel->users()->sync(array_values(array_filter(array_map('intval', (array) $request->input('staff', [])))));
        }
        HotelSettings::forget();
        Audit::record('settings.hotels', "Hotel updated: {$hotel->name}");

        return back()->with('success', "{$hotel->name} saved.");
    }

    /** The top-bar switcher: work in another hotel. */
    public function switch(Request $request)
    {
        $id = (int) $request->input('hotel_id');
        abort_unless($request->user()->canWorkAt($id), 403);
        $request->session()->put('hotel_id', $id);

        return redirect()->route('dashboard')->with('success', 'Now working in ' . Hotel::find($id)?->name . '.');
    }

    private function data(Request $request, ?Hotel $hotel = null): array
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'slug'        => ['nullable', 'string', 'max:80', 'alpha_dash', Rule::unique('hotels', 'slug')->ignore($hotel?->id)],
            'city'        => 'nullable|string|max:80',
            'state'       => 'nullable|string|max:80',
            'country'     => 'nullable|string|max:80',
            'address'     => 'nullable|string|max:255',
            'phone'       => 'nullable|string|max:40',
            'email'       => 'nullable|email|max:150',
            'description' => 'nullable|string|max:3000',
            'stars'       => 'nullable|integer|min:1|max:5',
            'sort_order'  => 'nullable|integer|min:0|max:999',
            'commission_percent' => 'nullable|numeric|min:0|max:50',
            'latitude'    => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude'   => 'nullable|numeric|between:-180,180|required_with:latitude',
            'logo_file'   => 'nullable|image|max:2048',
            'cover_file'  => 'nullable|image|max:5120',
        ]);
        foreach (['logo_file' => 'logo', 'cover_file' => 'cover_photo'] as $input => $column) {
            if ($request->hasFile($input)) {
                $data[$column] = $request->file($input)->store('hotels', 'public');
            }
            unset($data[$input]);
        }
        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_listed'] = $request->boolean('is_listed');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['commission_percent'] = ($data['commission_percent'] ?? '') === '' ? null : round((float) $data['commission_percent'], 2);
        if (empty($data['slug'])) {
            unset($data['slug']);
        }

        return $data;
    }
}
