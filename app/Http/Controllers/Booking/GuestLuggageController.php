<?php

namespace App\Http\Controllers\Booking;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking\LuggageItem;
use App\Models\Reservation;
use App\Services\Booking\LuggageService;
use Illuminate\Http\Request;

/**
 * The luggage list on the guest's booking page (/stay/{token}#luggage). Ticking
 * an item off answers JSON when the page asks for it, so the page doesn't
 * reload; everything else is a plain form.
 */
class GuestLuggageController extends Controller
{
    public function __construct(private LuggageService $luggage)
    {
    }

    public function store(Request $request, string $token)
    {
        $r = $this->reservation($token);
        $data = $request->validate(['text' => 'required|string|max:5000', 'bag' => 'nullable|string|max:60']);
        $items = $this->luggage->parse($data['text']);
        if (filled($data['bag'] ?? null)) {
            $items = array_map(fn ($i) => array_merge($i, ['bag' => $i['bag'] ?? $data['bag']]), $items);   // a bag written in the text wins
        }
        try {
            $n = $this->luggage->add($r, $items);
        } catch (BookingException $e) {
            return $this->back($token)->withInput()->with('error', $e->getMessage());
        }

        return $this->back($token)->with('success', $n === 1 ? 'Added to your luggage list.' : "{$n} items added to your luggage list.");
    }

    public function update(Request $request, string $token, int $item)
    {
        $r = $this->reservation($token);
        $data = $request->validate([
            'name'     => 'sometimes|required|string|max:120',
            'quantity' => 'sometimes|nullable|integer|min:1|max:' . LuggageService::MAX_QUANTITY,
            'bag'      => 'sometimes|nullable|string|max:60',
            'packed'   => 'sometimes|boolean',
        ]);
        try {
            $this->luggage->update($this->item($r, $item), $data);
        } catch (BookingException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422) : $this->back($token)->with('error', $e->getMessage());
        }

        return $request->expectsJson() ? response()->json(['luggage' => $this->luggage->forGuest($r->fresh())]) : $this->back($token);
    }

    public function destroy(string $token, int $item)
    {
        $r = $this->reservation($token);
        try {
            $this->luggage->remove($this->item($r, $item));
        } catch (BookingException $e) {
            return $this->back($token)->with('error', $e->getMessage());
        }

        return $this->back($token)->with('success', 'Removed from your luggage list.');
    }

    public function unpack(string $token)
    {
        $r = $this->reservation($token);
        try {
            $this->luggage->unpackAll($r);
        } catch (BookingException $e) {
            return $this->back($token)->with('error', $e->getMessage());
        }

        return $this->back($token)->with('success', 'Every item is unticked.');
    }

    private function back(string $token)
    {
        return redirect()->to(route('stay.show', $token) . '#luggage');
    }

    private function item(Reservation $r, int $id): LuggageItem
    {
        $item = LuggageItem::where('reservation_id', $r->id)->findOrFail($id);
        $item->setRelation('reservation', $r);

        return $item;
    }

    private function reservation(string $token): Reservation
    {
        abort_unless(strlen($token) === 48, 404);

        $r = Reservation::withoutGlobalScope('hotel')->where('guest_token', $token)->firstOrFail();
        \App\Support\Tenancy\CurrentHotel::get()->adopt($r);

        return $r;
    }
}
