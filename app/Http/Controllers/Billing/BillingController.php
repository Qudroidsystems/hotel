<?php

namespace App\Http\Controllers\Billing;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Pos\PosOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Money reports: what was collected (by method and cashier) and who still owes. */
class BillingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View folios')->only(['outstanding']);
    }

    /**
     * Collections for a date range. Finance staff see everyone; a cashier without
     * "View finance reports" sees only what they took themselves (their cash-up).
     */
    public function payments(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('View finance reports') || $user->can('Receive payments'), 403);
        $everyone = $user->can('View finance reports');

        $from = $this->date($request->input('from'), today());
        $to = $this->date($request->input('to'), $from);
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $method = PaymentMethod::tryFrom((string) $request->input('method'));
        $cashier = $everyone ? $request->integer('cashier') : $user->id;

        $query = Payment::with(['folio.guest', 'folio.reservation', 'receivedBy', 'voidedBy', 'bankAccount'])
            ->whereBetween('received_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($method, fn ($q) => $q->where('method', $method->value))
            ->when($cashier, fn ($q) => $q->where('received_by', $cashier));

        $all = (clone $query)->orderByDesc('received_at')->orderByDesc('id')->get();
        $valid = $all->whereNull('voided_at');

        // Bar & restaurant sales paid at the till (room charges are not money in — they go on guest bills).
        $pos = PosOrder::takings()->with('bankAccount')
            ->whereBetween('ordered_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($method, fn ($q) => $q->where('method', $method->value))
            ->when($cashier, fn ($q) => $q->where('cashier_id', $cashier))
            ->get(['id', 'method', 'total', 'bank_account_id', 'outlet_id']);

        $byMethod = collect(PaymentMethod::cases())->mapWithKeys(fn ($m) => [$m->value => [
            'method' => $m,
            'in'     => (float) $valid->where('method', $m)->where('kind', '!=', PaymentKind::Refund)->sum('amount'),
            'out'    => (float) $valid->where('method', $m)->where('kind', PaymentKind::Refund)->sum('amount'),
            'pos'    => (float) $pos->where('method', $m->value)->sum('total'),
            'count'  => $valid->where('method', $m)->count() + $pos->where('method', $m->value)->count(),
        ]])->filter(fn ($row) => $row['count'] > 0)
            ->map(fn ($row) => $row + ['net' => $row['in'] - $row['out'] + $row['pos']]);

        // Transfers per hotel bank account, to tick off against each bank statement.
        $byAccount = $valid->whereNotNull('bank_account_id')->groupBy('bank_account_id')
            ->map(fn ($g) => ['account' => $g->first()->bankAccount, 'amount' => (float) $g->sum(fn ($p) => $p->signed_amount)]);
        foreach ($pos->whereNotNull('bank_account_id')->groupBy('bank_account_id') as $id => $g) {
            $byAccount[$id] = ['account' => $g->first()->bankAccount, 'amount' => ($byAccount[$id]['amount'] ?? 0) + (float) $g->sum('total')];
        }

        $totals = [
            'received' => (float) $valid->where('kind', '!=', PaymentKind::Refund)->sum('amount'),
            'refunded' => (float) $valid->where('kind', PaymentKind::Refund)->sum('amount'),
            'pos'      => (float) $pos->sum('total'),
            'pos_count' => $pos->count(),
            'voided'   => $all->whereNotNull('voided_at')->count(),
        ];
        $totals['net'] = $totals['received'] - $totals['refunded'] + $totals['pos'];

        $cashiers = $everyone
            ? User::whereIn('id', Payment::query()->select('received_by')->distinct())
                ->orWhereIn('id', PosOrder::query()->select('cashier_id')->distinct())->orderBy('name')->get(['id', 'name'])
            : collect();
        $pagetitle = $everyone ? 'Collections' : 'My Collections';

        if ($request->boolean('print')) {
            return view('billing.print-collections', compact('all', 'byMethod', 'byAccount', 'totals', 'from', 'to', 'method', 'cashier', 'cashiers', 'everyone'));
        }

        return view('billing.payments', compact('pagetitle', 'all', 'byMethod', 'byAccount', 'totals', 'from', 'to', 'method', 'cashier', 'cashiers', 'everyone'));
    }

    /** Open bills with money owed either way — guests who owe, and credits to refund. */
    public function outstanding(Request $request)
    {
        $folios = Folio::where('status', 'open')->with(['guest', 'reservation.rooms.room'])->get();
        $balances = Folio::balances($folios->pluck('id')->all());

        $rows = $folios->map(function (Folio $f) use ($balances) {
            $f->setAttribute('totals', $balances[$f->id]);
            return $f;
        })->filter(fn ($f) => abs($f->totals['balance']) >= 0.01);

        $tab = in_array($request->input('tab'), ['departed', 'inhouse', 'credit', 'all'], true) ? $request->input('tab') : 'all';
        $groups = [
            'departed' => $rows->filter(fn ($f) => $f->totals['balance'] > 0 && $f->reservation && ! $f->reservation->status->isOpen()),
            'inhouse'  => $rows->filter(fn ($f) => $f->totals['balance'] > 0 && $f->reservation?->status->value === 'checked_in'),
            'credit'   => $rows->filter(fn ($f) => $f->totals['balance'] < 0),
            'all'      => $rows,
        ];
        $list = $groups[$tab]->sortByDesc(fn ($f) => abs($f->totals['balance']))->values();

        $stats = [
            'owed'     => $rows->filter(fn ($f) => $f->totals['balance'] > 0)->sum(fn ($f) => $f->totals['balance']),
            'departed' => $groups['departed']->sum(fn ($f) => $f->totals['balance']),
            'inhouse'  => $groups['inhouse']->sum(fn ($f) => $f->totals['balance']),
            'credit'   => -$groups['credit']->sum(fn ($f) => $f->totals['balance']),
        ];
        $counts = array_map(fn ($g) => $g->count(), $groups);
        $pagetitle = 'Outstanding Bills';

        return view('billing.outstanding', compact('pagetitle', 'list', 'stats', 'counts', 'tab'));
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
