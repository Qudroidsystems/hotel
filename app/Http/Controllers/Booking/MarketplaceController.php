<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\GroupSearch;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Http\Request;

/** The group booking site: all the group's hotels, searched together. One hotel: its own booking page. */
class MarketplaceController extends Controller
{
    public function index(Request $request, GroupSearch $search)
    {
        if (! CurrentHotel::multi()) {
            return redirect()->route('book.index', $request->query());
        }
        $request->session()->forget('book_hotel');
        $q = array_filter([
            'check_in'  => $request->query('check_in'),
            'check_out' => $request->query('check_out'),
            'adults'    => (int) $request->query('adults', 2),
            'children'  => (int) $request->query('children', 0),
            'rooms'     => (int) $request->query('rooms', 1),
            'promo'     => mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->query('promo', ''))), 0, 30),
        ], fn ($v) => $v !== null && $v !== '');
        $where = mb_substr(trim((string) $request->query('where', '')), 0, 60);
        $dated = isset($q['check_in'], $q['check_out']);

        return view('booking.hotels', [
            'rows' => $search->hotels($where, $dated ? $q : null), 'cities' => $search->cities(), 'q' => $q + ['adults' => 2, 'children' => 0, 'rooms' => 1],
            'where' => $where, 'dated' => $dated, 'title' => 'Hotels', 'brand' => config('hotel.group_name') ?: config('app.name'),
        ]);
    }

    /** /hotels/{slug}: that hotel's booking page. */
    public function show(Request $request, string $hotelSlug)
    {
        $request->session()->put('book_via_group', $hotelSlug);   // a booking from here goes through the group site
        return redirect()->route($request->query('check_in') ? 'book.search' : 'book.index', ['hotel' => $hotelSlug] + $request->query());
    }
}
