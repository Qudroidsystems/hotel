<?php

namespace App\Http\Controllers\Laundry;

use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Laundry\LaundryItem;
use App\Models\Laundry\LaundryOrder;
use App\Models\Reservation;
use App\Services\Laundry\LaundryService;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Guest laundry for staff: bags to collect, at the laundry, ready to take up; the count sheet; the price list. */
class LaundryController extends Controller
{
    public function __construct(private LaundryService $laundry)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['Handle laundry', 'Manage laundry']), 403);
        $live = LaundryOrder::live()->with(['reservation.guest:id,first_name,last_name,is_vip', 'collector:id,name'])
            ->orderByRaw("CASE status WHEN 'requested' THEN 0 WHEN 'ready' THEN 1 ELSE 2 END")->orderByRaw('COALESCE(due_at, created_at)')->get();
        $done = LaundryOrder::whereNotIn('status', LaundryOrder::LIVE)->where('updated_at', '>=', today()->subDay())
            ->with('handler:id,name')->latest('updated_at')->limit(40)->get();
        $data = [
            'pagetitle' => 'Laundry', 'live' => $live, 'done' => $done, 'svc' => $this->laundry,
            'hasPrices' => LaundryItem::active()->exists(), 'canHandle' => $this->laundry->canHandle($request->user()),
        ];

        return $request->boolean('partial') ? view('laundry.partials.board', $data) : view('laundry.index', $data);
    }

    /** The count sheet for a bag handed in at the desk. */
    public function create(Request $request)
    {
        abort_unless($this->laundry->canHandle($request->user()), 403);
        $inHouse = Reservation::where('status', ReservationStatus::CheckedIn)->with(['guest:id,first_name,last_name', 'rooms.room:id,number'])->get()
            ->map(fn ($r) => ['id' => $r->id, 'label' => ($r->rooms->pluck('room.number')->filter()->implode(', ') ?: '—') . ' · ' . ($r->guest?->full_name ?? $r->code)])
            ->sortBy('label', SORT_NATURAL)->values();

        return view('laundry.count', [
            'pagetitle' => 'Laundry handed in', 'order' => null, 'inHouse' => $inHouse, 'groups' => $this->laundry->items()->groupBy('group'),
            'counts' => [], 'svc' => $this->laundry, 'reservationId' => (int) $request->query('reservation'),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->laundry->canHandle($request->user()), 403);
        $data = $this->validateSheet($request, true);
        try {
            $order = $this->laundry->deskOrder(Reservation::findOrFail($data['reservation_id']), $request->user(), $this->lines($data), (bool) ($data['express'] ?? false), $data['note'] ?? null);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('laundry.index')->with('success', "{$order->number} counted: {$order->pieces} pieces, " . config('hotel.currency_symbol') . number_format((float) $order->total, 2) . " on room {$order->room_number}'s bill.");
    }

    /** The count sheet for a bag a guest asked to be collected, filled in from their list. */
    public function collectForm(Request $request, LaundryOrder $order)
    {
        abort_unless($this->laundry->canHandle($request->user()), 403);
        if ($order->status !== LaundryOrder::REQUESTED) {
            return redirect()->route('laundry.index')->with('error', "{$order->number} has already been dealt with.");
        }
        $counts = [];
        foreach ($order->lines ?? [] as $l) {
            $counts[$l['item_id']][$l['service']] = $l['quantity'];
        }

        return view('laundry.count', [
            'pagetitle' => "Count {$order->number}", 'order' => $order->load('reservation.guest'), 'inHouse' => collect(), 'groups' => $this->laundry->items()->groupBy('group'),
            'counts' => $counts, 'svc' => $this->laundry, 'reservationId' => $order->reservation_id,
        ]);
    }

    public function collect(Request $request, LaundryOrder $order)
    {
        abort_unless($this->laundry->canHandle($request->user()), 403);
        $data = $this->validateSheet($request, false);
        try {
            $order = $this->laundry->collect($order, $request->user(), $this->lines($data), (bool) ($data['express'] ?? false), $data['note'] ?? null);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('laundry.index')->with('success', "{$order->number} collected: {$order->pieces} pieces, " . config('hotel.currency_symbol') . number_format((float) $order->total, 2) . ' on the bill. Due back ' . $order->due_at->format('D g:i A') . '.');
    }

    public function act(Request $request, LaundryOrder $order, string $action)
    {
        abort_unless($this->laundry->canHandle($request->user()), 403);
        $text = trim((string) ($request->validate(['note' => 'nullable|string|max:500'])['note'] ?? '')) ?: null;
        try {
            match ($action) {
                'ready'    => $this->laundry->ready($order, $request->user()),
                'returned' => $this->laundry->returned($order, $request->user()),
                'decline'  => $text ? $this->laundry->decline($order, $request->user(), $text) : throw new BookingException('Say why, so the guest knows.'),
                'issue'    => $text ? $this->laundry->reportIssue($order, $request->user(), $text) : throw new BookingException('Describe the problem.'),
                default    => abort(404),
            };
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }
        $done = ['ready' => 'is ready to take up', 'returned' => 'returned to the room', 'decline' => 'declined', 'issue' => ': problem recorded on the booking'][$action];

        return back()->with('success', "{$order->number} {$done}.");
    }

    // ── Price list ────────────────────────────────────────────────────

    public function prices(Request $request)
    {
        abort_unless($request->user()->canAny(['Manage laundry', 'Handle laundry']), 403);

        return view('laundry.prices', [
            'pagetitle' => 'Laundry Price List', 'items' => LaundryItem::ordered()->get(), 'svc' => $this->laundry,
            'canManage' => $request->user()->can('Manage laundry'),
        ]);
    }

    public function savePrices(Request $request)
    {
        abort_unless($request->user()->can('Manage laundry'), 403);
        $data = $request->validate([
            'items'               => 'array|max:200',
            'items.*.id'          => 'nullable|integer',
            'items.*.name'        => 'nullable|string|max:80',
            'items.*.group'       => 'nullable|string|max:40',
            'items.*.wash_price'  => 'nullable|numeric|min:0|max:10000000',
            'items.*.dry_price'   => 'nullable|numeric|min:0|max:10000000',
            'items.*.press_price' => 'nullable|numeric|min:0|max:10000000',
            'items.*.is_active'   => 'nullable|boolean',
        ]);
        // Check every row first, so a mistake saves nothing.
        $rows = [];
        foreach (array_values($data['items'] ?? []) as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;   // empty new row; an existing item is switched off rather than removed (old bags refer to it)
            }
            $prices = collect(['wash_price', 'dry_price', 'press_price'])->mapWithKeys(fn ($k) => [$k => isset($row[$k]) && $row[$k] !== '' ? (float) $row[$k] : null])->all();
            if (! array_filter($prices, fn ($p) => $p !== null)) {
                return back()->withInput()->with('error', "Give “{$name}” at least one price.");
            }
            $item = ! empty($row['id']) ? LaundryItem::find($row['id']) : null;
            $rows[] = [$item, $prices + [
                'name' => $name, 'group' => trim((string) ($row['group'] ?? '')) ?: 'Other', 'sort_order' => $i + 1, 'is_active' => (bool) ($row['is_active'] ?? false) || ! $item,
            ]];
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($rows) {
            foreach ($rows as [$item, $values]) {
                ($item ?? new LaundryItem())->fill($values)->save();
            }
        });
        $n = count($rows);
        Audit::record('laundry.prices', "Updated the laundry price list ({$n} items)", by: $request->user());

        return back()->with('success', 'Price list saved.');
    }

    public function defaults(Request $request)
    {
        abort_unless($request->user()->can('Manage laundry'), 403);
        $n = $this->laundry->seedDefaults();

        return back()->with($n ? 'success' : 'error', $n ? "Added {$n} typical items. Change the prices to yours and save." : 'The price list already has items.');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function validateSheet(Request $request, bool $needsStay): array
    {
        return $request->validate(($needsStay ? ['reservation_id' => 'required|integer|exists:reservations,id'] : []) + [
            'qty'     => 'array',
            'qty.*'   => 'array',
            'qty.*.*' => 'nullable|integer|min:0|max:' . LaundryService::MAX_QUANTITY,
            'express' => 'nullable|boolean',
            'note'    => 'nullable|string|max:255',
        ]);
    }

    private function lines(array $data): array
    {
        $out = [];
        foreach ($data['qty'] ?? [] as $itemId => $services) {
            foreach ((array) $services as $service => $q) {
                if ((int) $q > 0) {
                    $out[] = ['item_id' => (int) $itemId, 'service' => (string) $service, 'quantity' => (int) $q];
                }
            }
        }

        return $out;
    }
}
