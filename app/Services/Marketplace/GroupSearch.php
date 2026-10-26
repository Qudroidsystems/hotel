<?php

namespace App\Services\Marketplace;

use App\Exceptions\BookingException;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Services\Booking\OnlineBookingService;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Support\Collection;

/**
 * The group booking site (multi-hotel): every listed hotel, and for given dates what each one has free and
 * its lowest price — each hotel's own rooms, rates and promo codes, exactly as its own booking page.
 */
class GroupSearch
{
    public function __construct(private OnlineBookingService $booking)
    {
    }

    /** Cities with a listed hotel, for the search box. */
    public function cities(): Collection
    {
        return Hotel::active()->where('is_listed', true)->whereNotNull('city')->orderBy('city')->distinct()->pluck('city');
    }

    /**
     * Listed hotels, optionally in a city (or matching a name). With dates, each has `bookable` (rooms of
     * a type left), `from` (lowest total for the stay) and `nights`; without, `from` is the lowest nightly rate.
     *
     * @param array{check_in?: string, check_out?: string, adults?: int, children?: int, rooms?: int, promo?: string}|null $q
     */
    public function hotels(?string $where = null, ?array $q = null): Collection
    {
        $where = trim((string) $where);
        $hotels = Hotel::active()->where('is_listed', true)->ordered()
            ->when($where !== '', fn ($h) => $h->where(fn ($w) => $w->where('city', 'like', "%{$where}%")->orWhere('state', 'like', "%{$where}%")->orWhere('name', 'like', "%{$where}%")))
            ->get();
        $dated = $q && ! empty($q['check_in']) && ! empty($q['check_out']);

        return $hotels->map(fn (Hotel $hotel) => CurrentHotel::get()->run($hotel, function () use ($hotel, $q, $dated) {
            $row = ['hotel' => $hotel, 'open' => (bool) config('hotel.booking.enabled', true), 'currency' => config('hotel.currency_symbol'),
                'from' => null, 'nights' => null, 'bookable' => null, 'problem' => null,
                'room_types' => RoomType::where('is_active', true)->count(),
                'nightly_from' => (float) RoomType::where('is_active', true)->whereHas('rooms', fn ($r) => $r->where('is_active', true))->min('base_rate')];
            if (! $dated || ! $row['open']) {
                return $row;
            }
            try {
                [$in, $out, $adults, $children, $rooms] = $this->booking->criteria($q);
            } catch (BookingException $e) {
                return ['problem' => $e->getMessage()] + $row;
            }
            $results = $this->booking->search($in, $out, $adults, $children, $rooms, $q['promo'] ?? null)->where('bookable', true);

            return array_merge($row, [
                'bookable' => $results->count(),
                'from'     => $results->isEmpty() ? null : (float) $results->min('total'),
                'nights'   => (int) $in->diffInDays($out),
            ]);
        }))->sortByDesc(fn ($r) => (int) (($r['bookable'] ?? 1) > 0))->values();   // hotels with rooms free first
    }
}
