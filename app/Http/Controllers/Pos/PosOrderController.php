<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PaymentMethod;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosOutlet;
use App\Models\User;
use App\Services\Pos\PosService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Back office for bar & restaurant sales: list, totals, voids, sorting out flagged sales. */
class PosOrderController extends Controller
{
    public function __construct(private PosService $pos)
    {
        $this->middleware('permission:Void POS orders')->only(['void']);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('View POS reports') || $user->can('Use POS'), 403);
        $everyone = $user->can('View POS reports');

        $from = $this->date($request->input('from'), today());
        $to = $this->date($request->input('to'), $from);
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $filters = [
            'outlet'  => $request->integer('outlet') ?: null,
            'status'  => in_array($request->input('status'), [PosOrder::PAID, PosOrder::CHARGED, PosOrder::ATTENTION, PosOrder::VOIDED], true) ? $request->input('status') : null,
            'cashier' => $everyone ? ($request->integer('cashier') ?: null) : $user->id,
            'q'       => trim((string) $request->input('q')),
        ];

        $base = PosOrder::query()
            ->whereBetween('ordered_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($filters['outlet'], fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($filters['cashier'], fn ($q, $v) => $q->where('cashier_id', $v));

        $period = (clone $base)->get(['id', 'outlet_id', 'status', 'method', 'total']);
        $live = $period->where('status', '!=', PosOrder::VOIDED);
        $totals = [
            'sales'     => (float) $live->sum('total'),
            'count'     => $live->count(),
            'taken'     => (float) $live->where('status', PosOrder::PAID)->sum('total'),
            'room'      => (float) $live->where('status', PosOrder::CHARGED)->sum('total'),
            'attention' => $period->where('status', PosOrder::ATTENTION)->count(),
            'voided'    => $period->where('status', PosOrder::VOIDED)->count(),
        ];
        $byMethod = $live->where('status', PosOrder::PAID)->groupBy('method')->map(fn ($g) => ['total' => (float) $g->sum('total'), 'count' => $g->count()]);
        $outlets = PosOutlet::ordered()->get();
        $byOutlet = $live->groupBy('outlet_id')->map(fn ($g) => ['total' => (float) $g->sum('total'), 'count' => $g->count()]);

        // Best sellers in the period.
        $top = \App\Models\Pos\PosOrderItem::query()
            ->whereIn('order_id', $live->pluck('id'))
            ->selectRaw('name, sum(quantity) as qty, sum(total) as amount')
            ->groupBy('name')->orderByDesc('amount')->limit(8)->get();

        $orders = (clone $base)
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$filters['q']}%")
                ->orWhere('guest_name', 'like', "%{$filters['q']}%")->orWhere('room_number', 'like', "%{$filters['q']}%")
                ->orWhere('table_label', 'like', "%{$filters['q']}%")))
            ->with(['outlet', 'cashier', 'items', 'reservation', 'bankAccount', 'voidedBy', 'resolvedBy'])
            ->orderByRaw("case when status = 'attention' then 0 else 1 end")->orderByDesc('ordered_at')
            ->paginate(40)->withQueryString();

        $cashiers = $everyone ? User::whereIn('id', PosOrder::query()->select('cashier_id')->distinct())->orderBy('name')->get(['id', 'name']) : collect();
        $inHouse = $user->can('Charge to room') ? $this->pos->inHouse() : [];
        $pagetitle = $everyone ? 'Bar & Restaurant Sales' : 'My Sales';

        return view('pos.orders', compact('pagetitle', 'orders', 'totals', 'byMethod', 'byOutlet', 'outlets', 'top', 'from', 'to', 'filters', 'cashiers', 'everyone', 'inHouse'));
    }

    public function receipt(Request $request, PosOrder $order)
    {
        abort_unless($request->user()->can('View POS reports') || $order->cashier_id === $request->user()->id, 403);
        $order->load(['outlet', 'items', 'cashier', 'bankAccount']);

        return view('pos.receipt', compact('order'));
    }

    public function void(Request $request, PosOrder $order)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->pos->void($order, $data['reason'], $request->user()), "Order {$order->number} voided.");
    }

    public function resolve(Request $request, PosOrder $order)
    {
        $user = $request->user();
        abort_unless($user->can('View POS reports') || $user->can('Void POS orders'), 403);
        $data = $request->validate([
            'method'         => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::Card->value, PaymentMethod::Transfer->value, PosOrder::ROOM])],
            'reservation_id' => 'nullable|required_if:method,room|integer|exists:reservations,id',
            'reference'      => 'nullable|string|max:100',
        ]);
        if ($data['method'] === PosOrder::ROOM) {
            abort_unless($user->can('Charge to room'), 403);
        }

        return $this->attempt(fn () => $this->pos->resolve($order, $data, $user), "Order {$order->number} settled.");
    }

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function date($value, Carbon $fallback): Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfDay() : $fallback->copy();
        } catch (\Throwable) {
            return $fallback->copy();
        }
    }
}
