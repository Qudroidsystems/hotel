<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Models\Booking\LuggageItem;
use App\Models\Reservation;
use App\Services\Booking\LuggageService;
use Illuminate\Http\Request;

/**
 * The guest app's luggage list (optional): what the guest brought, ticked off
 * as they pack on check-out day. Every answer is the whole list (see
 * LuggageService::forGuest).
 */
class GuestLuggageController extends ApiController
{
    public function __construct(private LuggageService $luggage)
    {
    }

    /** GET /guest/stays/{token}/luggage */
    public function index(string $token)
    {
        return $this->ok($this->luggage->forGuest($this->reservation($token)));
    }

    /** POST /guest/stays/{token}/luggage  {items: [{name, quantity, bag}]} or {text: "2 shirts\nCharger"} */
    public function store(Request $request, string $token)
    {
        $r = $this->reservation($token);
        $data = $request->validate([
            'items'            => 'required_without:text|array|max:' . LuggageService::MAX_ITEMS,
            'items.*.name'     => 'required|string|max:120',
            'items.*.quantity' => 'nullable|integer|min:1|max:' . LuggageService::MAX_QUANTITY,
            'items.*.bag'      => 'nullable|string|max:60',
            'text'             => 'required_without:items|nullable|string|max:5000',
        ]);
        try {
            $n = $this->luggage->add($r, $data['items'] ?? $this->luggage->parse($data['text'] ?? ''));
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->luggage->forGuest($r->fresh()), $n === 1 ? 'Added to your luggage list.' : "{$n} items added to your luggage list.", status: 201);
    }

    /** PATCH /guest/stays/{token}/luggage/{item}  {name?, quantity?, bag?, packed?} */
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
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->luggage->forGuest($r->fresh()));
    }

    /** DELETE /guest/stays/{token}/luggage/{item} */
    public function destroy(string $token, int $item)
    {
        $r = $this->reservation($token);
        try {
            $this->luggage->remove($this->item($r, $item));
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->luggage->forGuest($r->fresh()), 'Removed from your luggage list.');
    }

    /** POST /guest/stays/{token}/luggage/unpack — untick everything. */
    public function unpack(string $token)
    {
        $r = $this->reservation($token);
        try {
            $this->luggage->unpackAll($r);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->luggage->forGuest($r->fresh()), 'Every item is unticked.');
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
