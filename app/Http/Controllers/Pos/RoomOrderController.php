<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Pos\GuestOrder;
use App\Services\Pos\GuestOrderService;
use Illuminate\Http\Request;

/** Room orders: food and drinks guests ordered from the guest app or their room's QR card. */
class RoomOrderController extends Controller
{
    public function __construct(private GuestOrderService $orders)
    {
    }

    public function index(Request $request)
    {
        abort_unless($this->orders->canHandle($request->user()), 403);
        $live = GuestOrder::live()->with(['outlet:id,name,color,delivery_minutes', 'reservation.guest:id,first_name,last_name,is_vip', 'handler:id,name'])
            ->orderByRaw("CASE status WHEN 'placed' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END")->orderBy('created_at')->get();
        $done = GuestOrder::whereNotIn('status', GuestOrder::LIVE)->where('updated_at', '>=', today())
            ->with(['outlet:id,name', 'handler:id,name'])->latest('updated_at')->limit(40)->get();
        $outlets = $this->orders->outlets();
        $data = compact('live', 'done', 'outlets') + ['pagetitle' => 'Room Orders'];

        return $request->boolean('partial') ? view('pos.partials.room-orders-list', $data) : view('pos.room-orders', $data);
    }

    public function act(Request $request, GuestOrder $order, string $action)
    {
        $data = $request->validate(['note' => 'nullable|string|max:255']);
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        try {
            match ($action) {
                'accept'  => $this->orders->accept($order, $request->user(), $note),
                'send'    => $this->orders->send($order, $request->user()),
                'deliver' => $this->orders->deliver($order, $request->user()),
                'decline' => $note ? $this->orders->decline($order, $request->user(), $note) : throw new BookingException('Say why, so the guest knows.'),
                default   => abort(404),
            };
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }
        $done = ['accept' => 'accepted and added to the guest’s bill', 'send' => 'is on its way', 'deliver' => 'delivered', 'decline' => 'declined'][$action];

        return back()->with('success', "{$order->number} {$done}.");
    }
}
