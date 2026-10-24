<?php

namespace App\Http\Controllers\Billing;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\Pos\PosOutlet;
use App\Models\User;
use App\Services\Billing\CashShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Cashier shifts: open with a float, paid-ins/outs, cash count at close, manager sign-off. */
class CashShiftController extends Controller
{
    public function __construct(private CashShiftService $service)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canWork($user) || $this->canSeeAll($user), 403);
        $everyone = $this->canSeeAll($user);

        $from = $this->date($request->input('from'), today()->subDays(6));
        $to = $this->date($request->input('to'), today());
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $status = in_array($request->input('status'), [CashShift::OPEN, CashShift::CLOSED, CashShift::REVIEWED], true) ? $request->input('status') : null;
        $cashier = $everyone ? $request->integer('cashier') : $user->id;

        $shifts = CashShift::with('user:id,name', 'reviewedBy:id,name')
            ->where(fn ($q) => $q->whereBetween('opened_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->orWhere('status', CashShift::OPEN))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($cashier, fn ($q) => $q->where('user_id', $cashier))
            ->orderByRaw("case when status = 'open' then 0 when status = 'closed' then 1 else 2 end")
            ->orderByDesc('opened_at')
            ->get();

        $current = $this->service->current($user);
        $currentTotals = $current ? $this->service->totals($current) : null;
        $stats = [
            'open'     => $shifts->where('status', CashShift::OPEN)->count(),
            'awaiting' => $shifts->where('status', CashShift::CLOSED)->count(),
            'short'    => (float) $shifts->whereNotNull('variance')->filter(fn ($s) => $s->variance < 0)->sum('variance'),
            'over'     => (float) $shifts->whereNotNull('variance')->filter(fn ($s) => $s->variance > 0)->sum('variance'),
        ];
        $cashiers = $everyone ? User::whereIn('id', CashShift::query()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']) : collect();
        $outlets = PosOutlet::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']);
        $pagetitle = $everyone ? 'Cashier Shifts' : 'My Cash Shifts';

        return view('billing.shifts.index', compact('pagetitle', 'shifts', 'current', 'currentTotals', 'stats', 'everyone', 'cashiers', 'cashier', 'outlets', 'from', 'to', 'status'));
    }

    public function show(Request $request, CashShift $shift)
    {
        $this->authorizeShift($request->user(), $shift);
        $shift->load('user', 'reviewedBy', 'outlet', 'movements.user');
        $totals = $this->service->totals($shift);
        $pagetitle = "Shift {$shift->number}";

        if ($request->boolean('print')) {
            return view('billing.shifts.print', compact('shift', 'totals'));
        }

        return view('billing.shifts.show', compact('pagetitle', 'shift', 'totals'));
    }

    public function store(Request $request)
    {
        abort_unless($this->canWork($request->user()), 403);
        $data = $request->validate([
            'station'       => 'required|string|max:60',
            'outlet_id'     => 'nullable|integer|exists:pos_outlets,id',
            'opening_float' => 'required|numeric|min:0|max:100000000',
        ]);

        return $this->attempt(fn () => $this->service->open($request->user(), $data['station'], $data['outlet_id'] ?? null, (float) $data['opening_float']), 'Shift opened. Count your float into the drawer.');
    }

    public function movement(Request $request, CashShift $shift)
    {
        $this->authorizeShift($request->user(), $shift, true);
        $data = $request->validate([
            'type'   => ['required', Rule::in(array_keys(CashShiftMovement::TYPES))],
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'reason' => 'required|string|max:255',
        ]);

        return $this->attempt(fn () => $this->service->addMovement($shift, $data['type'], (float) $data['amount'], $data['reason']), CashShiftMovement::TYPES[$data['type']][0] . ' recorded.');
    }

    public function close(Request $request, CashShift $shift)
    {
        $this->authorizeShift($request->user(), $shift, true);
        $data = $request->validate([
            'counted_cash'    => 'required|numeric|min:0|max:100000000',
            'close_note'      => 'nullable|string|max:500',
            'denominations'   => 'nullable|array',
            'denominations.*' => 'nullable|integer|min:0|max:100000',
        ]);
        $notes = array_filter($data['denominations'] ?? [], fn ($n) => (int) $n > 0);

        return $this->attempt(fn () => $this->service->close($shift, (float) $data['counted_cash'], $data['close_note'] ?? null, $notes ?: null), 'Shift closed. Hand the cash and the printed summary to your supervisor.', $shift);
    }

    public function review(Request $request, CashShift $shift)
    {
        abort_unless($request->user()->can('Review cash shifts'), 403);
        $data = $request->validate(['review_note' => 'nullable|string|max:500']);

        return $this->attempt(fn () => $this->service->review($shift, $data['review_note'] ?? null), 'Shift signed off.');
    }

    // ------------------------------------------------------------------

    private function canWork(User $user): bool
    {
        return $user->canAny(['Use POS', 'Receive payments']);
    }

    private function canSeeAll(User $user): bool
    {
        return $user->canAny(['Review cash shifts', 'View finance reports']);
    }

    /** The cashier sees their own shifts; supervisors see (and may close) everyone's. */
    private function authorizeShift(User $user, CashShift $shift, bool $change = false): void
    {
        $own = $shift->user_id === $user->id;
        abort_unless($own || ($change ? $user->can('Review cash shifts') : $this->canSeeAll($user)), 403);
    }

    private function attempt(callable $action, string $success, ?CashShift $show = null)
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return ($show ? redirect()->route('cash-shifts.show', $show) : back())->with('success', $success);
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
