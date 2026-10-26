<?php

namespace App\Http\Middleware;

use App\Support\HotelSettings;
use App\Support\Tenancy\CurrentHotel;
use Closure;
use Illuminate\Http\Request;

/**
 * Multi-hotel: works out which hotel this request is for. Staff: the hotel they switched to (web) or sent in
 * the X-Hotel header (staff app), else their first hotel. Runs before route models are loaded, so a link
 * to another hotel's booking is simply not found. In a single-hotel system there is nothing to do.
 */
class SetCurrentHotel
{
    public function handle(Request $request, Closure $next)
    {
        if (! CurrentHotel::multi()) {
            return $next($request);
        }
        $current = CurrentHotel::get();
        $user = $request->user() ?? $request->user('sanctum');

        if ($user) {
            $hotels = $user->accessibleHotels();
            if ($hotels->isEmpty()) {
                if ($request->routeIs('logout') || $request->is('logout', 'api/v1/auth/logout*', 'api/v1/auth/me')) {
                    return $next($request);   // they can still sign out
                }
                abort(403, 'Your account isn’t linked to any hotel yet. Ask an administrator to add you to one.');
            }
            $wanted = (int) ($request->header('X-Hotel') ?: ($request->hasSession() ? $request->session()->get('hotel_id') : 0));
            // The hotel asked for, else the first one they are assigned to (owners see all, but start at their own).
            $own = \Illuminate\Support\Facades\DB::table('hotel_user')->where('user_id', $user->id)->pluck('hotel_id')->all();
            $hotel = $hotels->firstWhere('id', $wanted) ?? $hotels->first(fn ($h) => in_array($h->id, $own)) ?? $hotels->first();
            $current->set($hotel);
            if ($request->hasSession() && (int) $request->session()->get('hotel_id') !== $hotel->id) {
                $request->session()->put('hotel_id', $hotel->id);
            }
        } elseif ($slug = $request->route('hotelSlug') ?? $request->input('hotel') ?? ($request->hasSession() ? $request->session()->get('book_hotel') : null)) {
            // Public pages (booking site, guest app): the hotel named in the address, or the one the guest
            // was booking a moment ago (kept for the next pages of the booking).
            $hotel = \App\Models\Hotel::active()->where(is_numeric($slug) ? 'id' : 'slug', $slug)->first();
            $named = $request->route('hotelSlug') ?? $request->input('hotel');
            abort_if(! $hotel && $named, 404, 'That hotel isn’t taking bookings here.');
            if ($hotel) {
                $current->set($hotel);
                if ($request->hasSession()) {
                    $request->session()->put('book_hotel', $hotel->slug);
                }
            } elseif ($request->hasSession()) {
                $request->session()->forget('book_hotel');   // closed since: back to the main hotel
            }
        }
        HotelSettings::applyFor($current->id());

        return $next($request);
    }
}
