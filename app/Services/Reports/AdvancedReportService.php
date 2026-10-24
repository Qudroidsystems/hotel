<?php

namespace App\Services\Reports;

use App\Enums\ChargeType;
use App\Enums\PaymentKind;
use App\Enums\StockMovementType as T;
use App\Models\CashShift;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseCategory;
use App\Models\Folio;
use App\Models\FolioCharge;
use App\Models\Payment;
use App\Models\Pos\PosOrder;
use App\Models\Room;
use App\Models\User;
use App\Models\Inventory\PurchaseOrderLine;
use App\Models\Inventory\StockCount;
use App\Models\Inventory\StockCountLine;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReceipt;
use App\Models\Inventory\StockReceiptLine;
use App\Models\Inventory\SupplierInvoice;
use App\Models\Inventory\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Advanced financial and inventory reports (spec §9, §8.4, §13; Release 2).
 *
 * Every figure comes from the same records the rest of the system writes —
 * guest-bill charges, payments, POS orders, the stock ledger and procurement
 * documents — so the numbers here agree with the day-to-day screens.
 * Bar sales charged to a room are counted once, as POS sales, never again as
 * the bill charge they create (same rule as ManagementReportService).
 */
class AdvancedReportService
{
    /* =====================================================================
     |  FINANCIAL
     * ===================================================================== */

    /**
     * Gross profit for a period: revenue after tax, cost of the stock sold,
     * and stock lost or used in-house. Not a full profit & loss — salaries,
     * utilities and other running costs are not recorded in the system.
     */
    public function profit(Carbon $from, Carbon $to): array
    {
        [$start, $end] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $charges = $this->billCharges($from, $to);
        $orders = PosOrder::with('outlet:id,name')->whereBetween('ordered_at', [$start, $end])
            ->get(['id', 'outlet_id', 'status', 'total', 'tax_breakdown', 'discount_amount']);
        $sold = $orders->whereIn('status', [PosOrder::PAID, PosOrder::CHARGED]);

        $lines = collect();
        foreach ($charges->groupBy(fn ($c) => $c->type === ChargeType::Room ? 'rooms' : 'other') as $key => $g) {
            $gross = (float) $g->sum('total');
            $tax = $this->taxOf($g);
            $lines[$key] = ['label' => $key === 'rooms' ? 'Rooms' : 'Other guest-bill services', 'gross' => $gross, 'tax' => $tax, 'net' => $gross - $tax, 'cost' => 0.0];
        }

        // Stock cost behind each POS sale (cost at the time of sale, net of voids).
        $costByOrder = StockMovement::whereIn('type', [T::Sale->value, T::SaleVoid->value])
            ->where('source_type', (new PosOrder)->getMorphClass())->whereIn('source_id', $orders->pluck('id'))
            ->groupBy('source_id')->selectRaw('source_id, sum(value) as v')->pluck('v', 'source_id');

        $outlets = $orders->groupBy('outlet_id')->map(function ($g) use ($costByOrder) {
            $s = $g->whereIn('status', [PosOrder::PAID, PosOrder::CHARGED]);
            $gross = (float) $s->sum('total');
            $tax = $this->taxOf($s);
            $cost = -(float) $g->sum(fn ($o) => (float) ($costByOrder[$o->id] ?? 0));

            return ['label' => $g->first()->outlet?->name ?? 'Outlet', 'gross' => $gross, 'tax' => $tax, 'net' => $gross - $tax, 'cost' => $cost,
                'margin' => $gross - $tax - $cost, 'pct' => ($gross - $tax) > 0 ? ($gross - $tax - $cost) / ($gross - $tax) * 100 : null,
                'sales' => $s->count(), 'discounts' => (float) $s->sum('discount_amount')];
        })->sortByDesc('net')->values();

        $posNet = (float) $outlets->sum('net');
        $cogs = (float) $outlets->sum('cost');
        $lines['pos'] = ['label' => 'Bar & restaurant sales', 'gross' => (float) $outlets->sum('gross'), 'tax' => (float) $outlets->sum('tax'), 'net' => $posNet, 'cost' => $cogs];
        $lines = $lines->sortBy(fn ($l, $k) => array_search($k, ['rooms', 'pos', 'other']));

        // Stock that left without being sold (value is negative = cost).
        $other = StockMovement::whereIn('type', [T::Issue->value, T::Waste->value, T::Count->value, T::Adjustment->value])
            ->whereBetween('occurred_at', [$start, $end])->groupBy('type')->selectRaw('type, sum(value) as v')->pluck('v', 'type');
        $losses = [
            'issue'      => -(float) ($other[T::Issue->value] ?? 0),
            'waste'      => -(float) ($other[T::Waste->value] ?? 0),
            'count'      => -(float) ($other[T::Count->value] ?? 0),
            'adjustment' => -(float) ($other[T::Adjustment->value] ?? 0),
        ];

        $netRevenue = (float) $lines->sum('net');
        $gross = $netRevenue - $cogs;
        $afterStock = $gross - array_sum($losses);

        // Operating expenses (approved or paid, by the date the cost belongs to; VAT excluded).
        $expenses = Expense::counted()->with('category:id,name,group')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'category_id', 'department', 'amount', 'tax_amount', 'status']);
        $opex = $expenses->groupBy(fn ($e) => $e->category?->group ?? 'other')
            ->map(fn ($g, $group) => (object) [
                'label'      => ExpenseCategory::GROUPS[$group] ?? ucfirst($group),
                'amount'     => (float) $g->sum(fn ($e) => $e->net_amount),
                'categories' => $g->groupBy('category_id')->map(fn ($c) => (object) ['name' => $c->first()->category?->name, 'amount' => (float) $c->sum(fn ($e) => $e->net_amount), 'count' => $c->count()])
                    ->sortByDesc('amount')->values(),
            ])
            ->sortBy(fn ($g, $k) => array_search($k, array_keys(ExpenseCategory::GROUPS)))->values();
        $opexTotal = (float) $opex->sum('amount');
        $netProfit = $afterStock - $opexTotal;
        $byDepartment = $expenses->groupBy(fn ($e) => $e->department ?: 'Not given')->map(fn ($g) => (float) $g->sum(fn ($e) => $e->net_amount))->sortDesc();
        $unpaid = (float) $expenses->where('status', 'approved')->sum('amount');

        return [
            'lines'    => $lines->values(),
            'outlets'  => $outlets,
            'losses'   => $losses,
            'opex'     => $opex,
            'opex_departments' => $byDepartment,
            'totals'   => [
                'gross_revenue' => (float) $lines->sum('gross'),
                'tax'           => (float) $lines->sum('tax'),
                'net_revenue'   => $netRevenue,
                'cogs'          => $cogs,
                'gross_profit'  => $gross,
                'gp_pct'        => $netRevenue > 0 ? $gross / $netRevenue * 100 : null,
                'fb_cost_pct'   => $posNet > 0 ? $cogs / $posNet * 100 : null,
                'stock_losses'  => array_sum($losses),
                'after_stock'   => $afterStock,
                'opex'          => $opexTotal,
                'net_profit'    => $netProfit,
                'np_pct'        => $netRevenue > 0 ? $netProfit / $netRevenue * 100 : null,
                'opex_unpaid'   => $unpaid,
                'pending_opex'  => (float) Expense::where('status', 'pending')->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->sum('amount'),
                'purchases'     => (float) StockReceipt::whereBetween('received_on', [$from->toDateString(), $to->toDateString()])->sum('total'),
                'supplier_paid' => (float) SupplierPayment::whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->sum('amount'),
            ],
        ];
    }

    /** Tax charged in the period, by tax and rate, with the month-by-month split. */
    public function taxReport(Carbon $from, Carbon $to): array
    {
        $rows = collect();
        foreach ($this->billCharges($from, $to) as $c) {
            $rows->push(['date' => $c->service_date, 'source' => 'Guest bills', 'total' => (float) $c->total, 'taxes' => $c->tax_breakdown ?? []]);
        }
        PosOrder::whereIn('status', [PosOrder::PAID, PosOrder::CHARGED])
            ->whereBetween('ordered_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['total', 'tax_breakdown', 'ordered_at'])
            ->each(fn ($o) => $rows->push(['date' => $o->ordered_at, 'source' => 'Bar & restaurant', 'total' => (float) $o->total, 'taxes' => $o->tax_breakdown ?? []]));

        $byTax = [];
        $byMonth = [];
        foreach ($rows as $r) {
            $month = $r['date']->format('Y-m');
            $byMonth[$month] ??= ['month' => Carbon::parse($month . '-01'), 'sales' => 0.0, 'tax' => 0.0, 'taxes' => []];
            $byMonth[$month]['sales'] += $r['total'];
            foreach ($r['taxes'] as $t) {
                $amount = (float) ($t['amount'] ?? 0);
                $name = (string) ($t['name'] ?? 'Tax');
                $key = $name . '|' . (float) ($t['rate'] ?? 0);
                $byTax[$key] ??= ['name' => $name, 'rate' => (float) ($t['rate'] ?? 0), 'amount' => 0.0, 'base' => 0.0, 'bills' => 0.0, 'pos' => 0.0, 'lines' => 0];
                $byTax[$key]['amount'] += $amount;
                $byTax[$key]['base'] += $r['total'];
                $byTax[$key][$r['source'] === 'Guest bills' ? 'bills' : 'pos'] += $amount;
                $byTax[$key]['lines']++;
                $byMonth[$month]['tax'] += $amount;
                $byMonth[$month]['taxes'][$name] = ($byMonth[$month]['taxes'][$name] ?? 0) + $amount;
            }
        }
        ksort($byMonth);

        return [
            'by_tax'   => collect($byTax)->sortByDesc('amount')->values(),
            'by_month' => collect($byMonth)->values(),
            'names'    => collect($byTax)->pluck('name')->unique()->values(),
            'sales'    => (float) $rows->sum('total'),
            'untaxed'  => (float) $rows->filter(fn ($r) => empty($r['taxes']))->sum('total'),
            'total'    => (float) collect($byTax)->sum('amount'),
        ];
    }

    /**
     * Twelve months of trading, month by month, ending with the month of $to:
     * occupancy, ADR, RevPAR, revenue by stream, cost of sales and money in.
     */
    public function trend(Carbon $to, int $months = 12): array
    {
        $first = $to->copy()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $last = $to->copy()->endOfMonth();
        $rooms = Room::active()->count();

        $charges = $this->billCharges($first, $last);
        $pos = PosOrder::whereIn('status', [PosOrder::PAID, PosOrder::CHARGED])->whereBetween('ordered_at', [$first, $last])->get(['id', 'status', 'total', 'tax_breakdown', 'ordered_at']);
        $payments = Payment::valid()->whereBetween('received_at', [$first, $last])->get(['kind', 'amount', 'received_at']);
        $cost = StockMovement::whereIn('type', [T::Sale->value, T::SaleVoid->value])->whereBetween('occurred_at', [$first, $last])->get(['value', 'occurred_at']);
        $spend = Expense::counted()->whereBetween('expense_date', [$first->toDateString(), $last->toDateString()])->get(['amount', 'tax_amount', 'expense_date']);

        $key = fn ($d) => $d->format('Y-m');
        $chargesBy = $charges->groupBy(fn ($c) => $key($c->service_date));
        $posBy = $pos->groupBy(fn ($o) => $key($o->ordered_at));
        $payBy = $payments->groupBy(fn ($p) => $key($p->received_at));
        $costBy = $cost->groupBy(fn ($m) => $key($m->occurred_at));
        $spendBy = $spend->groupBy(fn ($e) => $key($e->expense_date));

        $rows = collect();
        for ($m = $first->copy(); $m->lte($last); $m->addMonthNoOverflow()) {
            $k = $key($m);
            $c = $chargesBy[$k] ?? collect();
            $roomLines = $c->filter(fn ($x) => $x->type === ChargeType::Room);
            $o = $posBy[$k] ?? collect();
            // Nights available: whole month, or up to today for the current month.
            $days = $m->isSameMonth(today()) ? today()->day : ($m->gt(today()) ? 0 : $m->daysInMonth);
            $available = $rooms * $days;
            $room = (float) $roomLines->sum('total');
            $sold = $roomLines->count();
            $posRev = (float) $o->sum('total');
            $otherRev = (float) $c->sum('total') - $room;
            $revenue = $room + $posRev + $otherRev;
            $tax = $this->taxOf($c) + $this->taxOf($o);
            $cogs = -(float) ($costBy[$k] ?? collect())->sum('value');
            $opex = (float) ($spendBy[$k] ?? collect())->sum(fn ($e) => $e->net_amount);

            $rows->push([
                'month'     => $m->copy(),
                'sold'      => $sold,
                'occupancy' => $available ? round($sold / $available * 100, 1) : 0,
                'adr'       => $sold ? $room / $sold : 0,
                'revpar'    => $available ? $room / $available : 0,
                'room'      => $room,
                'pos'       => $posRev,
                'other'     => $otherRev,
                'revenue'   => $revenue,
                'tax'       => $tax,
                'cogs'      => $cogs,
                'gross'     => $revenue - $tax - $cogs,
                'opex'      => $opex,
                'net'       => $revenue - $tax - $cogs - $opex,
                'collected' => (float) ($payBy[$k] ?? collect())->sum(fn ($p) => $p->signed_amount) + (float) $o->where('status', PosOrder::PAID)->sum('total'),
            ]);
        }

        $best = $rows->sortByDesc('revenue')->first();

        return [
            'rows'   => $rows,
            'rooms'  => $rooms,
            'max'    => max(1, (float) $rows->max('revenue')),
            'best'   => $best && $best['revenue'] > 0 ? $best : null,
            'totals' => [
                'revenue'   => (float) $rows->sum('revenue'),
                'room'      => (float) $rows->sum('room'),
                'pos'       => (float) $rows->sum('pos'),
                'other'     => (float) $rows->sum('other'),
                'cogs'      => (float) $rows->sum('cogs'),
                'gross'     => (float) $rows->sum('gross'),
                'opex'      => (float) $rows->sum('opex'),
                'net'       => (float) $rows->sum('net'),
                'collected' => (float) $rows->sum('collected'),
                'sold'      => (int) $rows->sum('sold'),
            ],
        ];
    }

    /**
     * Who owes the hotel, and whom the hotel owes, by how long it has been
     * owed. Guests are aged from departure (in-house guests are shown apart);
     * supplier invoices from their due date.
     */
    public function ageing(): array
    {
        $buckets = ['current' => 'In house / not yet due', 'd30' => '1–30 days', 'd60' => '31–60 days', 'd90' => '61–90 days', 'over' => 'Over 90 days'];
        $bucket = fn (int $days) => $days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'over'));
        $empty = array_fill_keys(array_keys($buckets), 0.0);

        // ── Guests ───────────────────────────────────────────────────
        $folios = Folio::where('status', 'open')->with(['guest:id,title,first_name,last_name', 'reservation:id,reference,status,check_out_date'])->get();
        $balances = Folio::balances($folios->pluck('id')->all());
        $guests = $folios->map(function (Folio $f) use ($balances, $bucket) {
            $balance = $balances[$f->id]['balance'] ?? 0;
            if ($balance < 0.01) {
                return null;
            }
            $res = $f->reservation;
            $inHouse = $res && $res->status->isOpen();
            $since = $res?->check_out_date ?? $f->created_at;
            $days = $inHouse ? 0 : max(0, (int) $since->copy()->startOfDay()->diffInDays(today()));

            return ['folio' => $f, 'balance' => $balance, 'days' => $days, 'bucket' => $inHouse ? 'current' : $bucket(max(1, $days)), 'in_house' => $inHouse];
        })->filter()->sortByDesc('days')->values();

        // ── Suppliers ────────────────────────────────────────────────
        $invoices = SupplierInvoice::open()->with('supplier:id,name')->get();
        $suppliers = $invoices->groupBy('supplier_id')->map(function ($g) use ($bucket, $empty) {
            $row = ['supplier' => $g->first()->supplier, 'invoices' => $g->count(), 'total' => 0.0] + $empty;
            foreach ($g as $i) {
                $owed = round((float) $i->amount - (float) $i->paid, 2);
                $late = $i->due_date->lt(today()) ? (int) $i->due_date->diffInDays(today()) : 0;
                $row[$late > 0 ? $bucket($late) : 'current'] += $owed;
                $row['total'] += $owed;
            }

            return $row;
        })->sortByDesc('total')->values();

        $guestTotals = $empty;
        foreach ($guests as $g) {
            $guestTotals[$g['bucket']] += $g['balance'];
        }
        $supplierTotals = $empty;
        foreach (array_keys($empty) as $b) {
            $supplierTotals[$b] = (float) $suppliers->sum($b);
        }

        return [
            'buckets'         => $buckets,
            'guests'          => $guests,
            'guest_totals'    => $guestTotals,
            'guest_total'     => array_sum($guestTotals),
            'suppliers'       => $suppliers,
            'supplier_totals' => $supplierTotals,
            'supplier_total'  => array_sum($supplierTotals),
        ];
    }

    /**
     * Discounts, voids, refunds and cash differences by the member of staff
     * responsible, for management review (spec §7.3, §14). An exception is a
     * prompt to look closer, not proof that anything is wrong.
     */
    public function exceptions(Carbon $from, Carbon $to): array
    {
        [$start, $end] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $staff = [];
        $items = collect();
        $add = function (?int $userId, string $col, float $amount, int $n = 1) use (&$staff) {
            $id = $userId ?? 0;
            $staff[$id] ??= ['user_id' => $id, 'pos_discount' => 0.0, 'pos_discount_n' => 0, 'pos_void' => 0.0, 'pos_void_n' => 0,
                'bill_discount' => 0.0, 'bill_discount_n' => 0, 'charge_void' => 0.0, 'charge_void_n' => 0,
                'refund' => 0.0, 'refund_n' => 0, 'payment_void' => 0.0, 'payment_void_n' => 0,
                'cash_short' => 0.0, 'cash_over' => 0.0, 'shifts' => 0];
            $staff[$id][$col] += $amount;
            if (isset($staff[$id][$col . '_n'])) {
                $staff[$id][$col . '_n'] += $n;
            }
        };

        $discounted = PosOrder::where('status', '!=', PosOrder::VOIDED)->where('discount_amount', '>', 0)->whereBetween('ordered_at', [$start, $end])
            ->get(['id', 'number', 'cashier_id', 'discount_amount', 'discount_reason', 'ordered_at']);
        foreach ($discounted as $o) {
            $add($o->cashier_id, 'pos_discount', (float) $o->discount_amount);
            $items->push(['kind' => 'Bar discount', 'ref' => $o->number, 'user_id' => $o->cashier_id, 'amount' => (float) $o->discount_amount, 'reason' => $o->discount_reason, 'at' => $o->ordered_at]);
        }

        $voided = PosOrder::where('status', PosOrder::VOIDED)->whereBetween('voided_at', [$start, $end])->get(['id', 'number', 'voided_by', 'cashier_id', 'total', 'void_reason', 'voided_at']);
        foreach ($voided as $o) {
            $add($o->voided_by, 'pos_void', (float) $o->total);
            $items->push(['kind' => 'Bar sale voided', 'ref' => $o->number, 'user_id' => $o->voided_by, 'amount' => (float) $o->total, 'reason' => $o->void_reason, 'at' => $o->voided_at]);
        }

        $billDiscounts = FolioCharge::with('folio:id,number')->whereNull('voided_at')->where('type', ChargeType::Discount->value)
            ->whereBetween('created_at', [$start, $end])->get(['id', 'folio_id', 'posted_by', 'total', 'description', 'created_at']);
        foreach ($billDiscounts as $c) {
            $add($c->posted_by, 'bill_discount', abs((float) $c->total));
            $items->push(['kind' => 'Bill discount', 'ref' => $c->folio?->number, 'user_id' => $c->posted_by, 'amount' => abs((float) $c->total), 'reason' => $c->description, 'at' => $c->created_at]);
        }

        $chargeVoids = FolioCharge::with('folio:id,number')->whereBetween('voided_at', [$start, $end])
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', (new PosOrder)->getMorphClass()))   // bar voids are counted above
            ->get(['id', 'folio_id', 'voided_by', 'total', 'description', 'void_reason', 'voided_at']);
        foreach ($chargeVoids as $c) {
            $add($c->voided_by, 'charge_void', abs((float) $c->total));
            $items->push(['kind' => 'Bill charge voided', 'ref' => $c->folio?->number, 'user_id' => $c->voided_by, 'amount' => abs((float) $c->total), 'reason' => trim($c->description . ' — ' . $c->void_reason, ' —'), 'at' => $c->voided_at]);
        }

        $refunds = Payment::valid()->where('kind', PaymentKind::Refund->value)->whereBetween('received_at', [$start, $end])->get(['id', 'receipt_number', 'received_by', 'amount', 'notes', 'received_at']);
        foreach ($refunds as $p) {
            $add($p->received_by, 'refund', (float) $p->amount);
            $items->push(['kind' => 'Refund', 'ref' => $p->receipt_number, 'user_id' => $p->received_by, 'amount' => (float) $p->amount, 'reason' => $p->notes, 'at' => $p->received_at]);
        }

        $paymentVoids = Payment::whereBetween('voided_at', [$start, $end])->get(['id', 'receipt_number', 'voided_by', 'amount', 'void_reason', 'voided_at']);
        foreach ($paymentVoids as $p) {
            $add($p->voided_by, 'payment_void', (float) $p->amount);
            $items->push(['kind' => 'Payment voided', 'ref' => $p->receipt_number, 'user_id' => $p->voided_by, 'amount' => (float) $p->amount, 'reason' => $p->void_reason, 'at' => $p->voided_at]);
        }

        $tolerance = (float) config('hotel.cash.variance_tolerance');
        $shifts = CashShift::whereIn('status', [CashShift::CLOSED, CashShift::REVIEWED])->whereBetween('closed_at', [$start, $end])
            ->get(['id', 'number', 'user_id', 'variance', 'close_note', 'closed_at']);
        foreach ($shifts as $s) {
            $v = (float) $s->variance;
            $add($s->user_id, 'shifts', 1, 0);
            $add($s->user_id, $v < 0 ? 'cash_short' : 'cash_over', abs($v), 0);
            if (abs($v) > $tolerance) {
                $items->push(['kind' => $v < 0 ? 'Cash short' : 'Cash over', 'ref' => $s->number, 'user_id' => $s->user_id, 'amount' => abs($v), 'reason' => $s->close_note, 'at' => $s->closed_at]);
            }
        }

        $users = User::whereIn('id', array_keys($staff))->pluck('name', 'id');
        $rows = collect($staff)->map(function ($r) use ($users) {
            $r['name'] = $r['user_id'] ? ($users[$r['user_id']] ?? 'Former user') : 'Not recorded';
            $r['total'] = $r['pos_discount'] + $r['pos_void'] + $r['bill_discount'] + $r['charge_void'] + $r['refund'] + $r['payment_void'] + $r['cash_short'];

            return $r;
        })->sortByDesc('total')->values();

        $flag = (float) config('hotel.audit.flag_amount');
        $items = $items->map(fn ($i) => $i + ['name' => $i['user_id'] ? ($users[$i['user_id']] ?? 'Former user') : '—', 'big' => $i['amount'] >= $flag])
            ->sortByDesc('amount')->values();

        $sum = fn ($col) => (float) $rows->sum($col);

        return [
            'rows'      => $rows,
            'items'     => $items->take(50),
            'item_count' => $items->count(),
            'tolerance' => $tolerance,
            'flag'      => $flag,
            'totals'    => [
                'pos_discount' => $sum('pos_discount'), 'pos_void' => $sum('pos_void'), 'bill_discount' => $sum('bill_discount'),
                'charge_void'  => $sum('charge_void'), 'refund' => $sum('refund'), 'payment_void' => $sum('payment_void'),
                'cash_short'   => $sum('cash_short'), 'cash_over' => $sum('cash_over'), 'shifts' => (int) $rows->sum('shifts'),
                'big'          => $items->where('big', true)->count(),
            ],
        ];
    }

    /* =====================================================================
     |  INVENTORY
     * ===================================================================== */

    /**
     * Stock health: average usage, days of cover, what to reorder and how
     * much, and stock that is not moving (spec §8.4: par levels, forecasting,
     * slow-moving alerts).
     */
    public function stockHealth(?int $locationId = null, ?int $categoryId = null): array
    {
        $usageDays = max(1, (int) config('hotel.inventory.usage_days', 30));
        $coverDays = max(1, (int) config('hotel.inventory.cover_days', 14));
        $slowDays = max(1, (int) config('hotel.inventory.slow_moving_days', 60));

        // What counts as "used": sales, in-house use and waste. For one store,
        // stock sent to other stores is that store's usage too.
        $useTypes = [T::Sale->value, T::SaleVoid->value, T::Issue->value, T::Waste->value];
        if ($locationId) {
            $useTypes[] = T::TransferOut->value;
        }

        $items = StockItem::active()->with('category:id,name')
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name')->get();
        $ids = $items->pluck('id');

        $onHand = DB::table('stock_levels')->whereIn('item_id', $ids)->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('item_id')->selectRaw('item_id, sum(quantity) as q')->pluck('q', 'item_id');
        $used = StockMovement::whereIn('item_id', $ids)->whereIn('type', $useTypes)->where('occurred_at', '>=', now()->subDays($usageDays))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('item_id')->selectRaw('item_id, sum(quantity) as q')->pluck('q', 'item_id');
        $lastUsed = StockMovement::whereIn('item_id', $ids)->whereIn('type', $useTypes)->where('quantity', '<', 0)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('item_id')->selectRaw('item_id, max(occurred_at) as at')->pluck('at', 'item_id');
        $lastIn = StockMovement::whereIn('item_id', $ids)->whereIn('type', [T::Receipt->value, T::Opening->value, T::TransferIn->value])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('item_id')->selectRaw('item_id, max(occurred_at) as at')->pluck('at', 'item_id');

        $rows = $items->map(function (StockItem $item) use ($onHand, $used, $lastUsed, $lastIn, $usageDays, $coverDays, $slowDays, $locationId) {
            $qty = (float) ($onHand[$item->id] ?? 0);
            $usage = max(0, -(float) ($used[$item->id] ?? 0));
            $daily = $usage / $usageDays;
            $cover = $daily > 0 ? $qty / $daily : null;
            $last = isset($lastUsed[$item->id]) ? Carbon::parse($lastUsed[$item->id]) : null;
            $since = $last ? (int) $last->diffInDays(now()) : null;
            $reorder = (float) $item->reorder_level;

            $status = match (true) {
                $qty <= 0 && ($usage > 0 || $reorder > 0)                 => 'out',
                $qty <= 0                                                 => 'empty',
                ! $locationId && $reorder > 0 && $qty <= $reorder         => 'reorder',
                $cover !== null && $cover < $coverDays                    => 'low',
                $last === null && $this->olderThan($lastIn[$item->id] ?? $item->created_at, $slowDays) => 'dead',
                $since !== null && $since >= $slowDays                    => 'slow',
                $cover !== null && $cover > $coverDays * 4 && $qty * (float) $item->avg_cost > 0 => 'over',
                default                                                   => 'ok',
            };

            $suggest = 0.0;
            if (in_array($status, ['out', 'reorder', 'low'], true)) {
                $suggest = max(0, ceil($daily * $coverDays + ($locationId ? 0 : $reorder) - $qty));
                if ($suggest > 0 && $item->reorder_qty) {
                    $suggest = max($suggest, (float) $item->reorder_qty);
                }
            }

            return (object) [
                'item' => $item, 'qty' => $qty, 'value' => max(0, $qty) * (float) $item->avg_cost,
                'usage' => $usage, 'daily' => $daily, 'cover' => $cover, 'last_used' => $last, 'days_idle' => $since,
                'status' => $status, 'suggest' => $suggest, 'suggest_value' => $suggest * (float) ($item->last_cost ?: $item->avg_cost),
            ];
        })->filter(fn ($r) => $r->status !== 'empty' || $r->usage > 0);

        $count = fn ($s) => $rows->where('status', $s)->count();

        return [
            'rows'       => $rows->sortBy(fn ($r) => [array_search($r->status, ['out', 'reorder', 'low', 'dead', 'slow', 'over', 'ok', 'empty']), $r->cover ?? PHP_INT_MAX])->values(),
            'statuses'   => self::STOCK_STATUS,
            'counts'     => collect(array_keys(self::STOCK_STATUS))->mapWithKeys(fn ($s) => [$s => $count($s)]),
            'idle_value' => (float) $rows->whereIn('status', ['dead', 'slow'])->sum('value'),
            'over_value' => (float) $rows->where('status', 'over')->sum('value'),
            'value'      => (float) $rows->sum('value'),
            'order_value' => (float) $rows->sum('suggest_value'),
            'settings'   => compact('usageDays', 'coverDays', 'slowDays'),
        ];
    }

    public const STOCK_STATUS = [
        'out'     => ['Out of stock', 'st-danger'],
        'reorder' => ['At reorder level', 'st-danger'],
        'low'     => ['Running low', 'st-warning'],
        'dead'    => ['Never used', 'st-muted'],
        'slow'    => ['Slow-moving', 'st-muted'],
        'over'    => ['Overstocked', 'st-info'],
        'ok'      => ['Healthy', 'st-success'],
    ];

    /** Stocktake differences: each posted count, and the items that are repeatedly short. */
    public function variance(Carbon $from, Carbon $to, ?int $locationId = null): array
    {
        $counts = StockCount::with(['location:id,name', 'category:id,name'])->where('status', 'posted')
            ->whereBetween('posted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->orderByDesc('posted_at')->get();

        $lines = StockCountLine::with('item:id,name,unit')->whereIn('stock_count_id', $counts->pluck('id'))->whereNotNull('counted_quantity')->get();
        $diff = fn ($l) => (float) $l->counted_quantity - (float) $l->expected_quantity;
        $value = fn ($l) => round($diff($l) * (float) $l->unit_cost, 2);

        $perCount = $lines->groupBy('stock_count_id');
        $countRows = $counts->map(function ($c) use ($perCount, $diff, $value) {
            $ls = $perCount[$c->id] ?? collect();
            $off = $ls->filter(fn ($l) => abs($diff($l)) > 0.0005);

            return ['count' => $c, 'lines' => $ls->count(), 'off' => $off->count(),
                'accuracy' => $ls->count() ? ($ls->count() - $off->count()) / $ls->count() * 100 : null,
                'short' => -(float) $off->sum(fn ($l) => min(0, $value($l))), 'over' => (float) $off->sum(fn ($l) => max(0, $value($l))),
                'net' => (float) $off->sum($value)];
        });

        $items = $lines->filter(fn ($l) => abs($diff($l)) > 0.0005)->groupBy('item_id')->map(fn ($g) => (object) [
            'item'   => $g->first()->item,
            'times'  => $g->count(),
            'counts' => $perCount->filter(fn ($ls) => $ls->contains('item_id', $g->first()->item_id))->count(),
            'qty'    => (float) $g->sum($diff),
            'value'  => (float) $g->sum($value),
        ])->sortBy('value')->values();

        $total = $lines->count();
        $off = $lines->filter(fn ($l) => abs($diff($l)) > 0.0005)->count();

        return [
            'counts' => $countRows,
            'items'  => $items,
            'totals' => [
                'counts'   => $counts->count(),
                'lines'    => $total,
                'accuracy' => $total ? ($total - $off) / $total * 100 : null,
                'short'    => (float) $countRows->sum('short'),
                'over'     => (float) $countRows->sum('over'),
                'net'      => (float) $countRows->sum('net'),
            ],
        ];
    }

    /**
     * Purchasing: spend per supplier, how completely and on time they
     * delivered, and price changes against the last price paid (spec §8.3).
     */
    public function purchasing(Carbon $from, Carbon $to): array
    {
        [$d1, $d2] = [$from->toDateString(), $to->toDateString()];
        $jump = (float) config('hotel.procurement.price_jump_percent');

        $receipts = StockReceipt::with(['supplier:id,name', 'purchaseOrder:id,expected_on'])->whereBetween('received_on', [$d1, $d2])->get();
        $orderLines = PurchaseOrderLine::with('order:id,supplier_id,status,ordered_at')
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('ordered_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]))
            ->get();
        $rejected = StockReceiptLine::whereIn('stock_receipt_id', $receipts->pluck('id'))->groupBy('stock_receipt_id')
            ->selectRaw('stock_receipt_id, sum(rejected_quantity * unit_cost) as v')->pluck('v', 'stock_receipt_id');

        $suppliers = $receipts->groupBy(fn ($r) => $r->supplier_id ?? 0)->map(function ($g) use ($rejected) {
            $withPo = $g->filter(fn ($r) => $r->purchaseOrder?->expected_on);

            return ['supplier' => $g->first()->supplier, 'receipts' => $g->count(), 'spend' => (float) $g->sum('total'),
                'rejected' => (float) $g->sum(fn ($r) => (float) ($rejected[$r->id] ?? 0)),
                'on_time' => $withPo->count() ? $withPo->filter(fn ($r) => $r->received_on->lte($r->purchaseOrder->expected_on))->count() / $withPo->count() * 100 : null,
                'ordered' => 0.0, 'received' => 0.0];
        });
        foreach ($orderLines->groupBy(fn ($l) => $l->order->supplier_id) as $sid => $g) {
            $row = $suppliers[$sid] ?? ['supplier' => \App\Models\Inventory\Supplier::find($sid, ['id', 'name']), 'receipts' => 0, 'spend' => 0.0, 'rejected' => 0.0, 'on_time' => null, 'ordered' => 0.0, 'received' => 0.0];
            $row['ordered'] = (float) $g->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_cost);
            $row['received'] = (float) $g->sum(fn ($l) => min((float) $l->received_quantity, (float) $l->quantity) * (float) $l->unit_cost);
            $suppliers[$sid] = $row;
        }
        $suppliers = collect($suppliers)->map(fn ($r) => $r + ['fill' => $r['ordered'] > 0 ? $r['received'] / $r['ordered'] * 100 : null])->sortByDesc('spend')->values();

        // Every receipt line in the period, against the previous price paid for that item.
        $lines = StockReceiptLine::with(['item:id,name,unit', 'receipt:id,number,supplier_id,received_on', 'receipt.supplier:id,name'])
            ->whereIn('stock_receipt_id', $receipts->pluck('id'))->get()
            ->sortBy(fn ($l) => [$l->receipt->received_on->toDateString(), $l->id])->values();
        $firstDate = $lines->min(fn ($l) => $l->receipt->received_on->toDateString());
        $history = StockReceiptLine::query()->join('stock_receipts', 'stock_receipts.id', '=', 'stock_receipt_lines.stock_receipt_id')
            ->whereIn('stock_receipt_lines.item_id', $lines->pluck('item_id')->unique())
            ->when($firstDate, fn ($q) => $q->where('stock_receipts.received_on', '<', $firstDate))
            ->orderBy('stock_receipts.received_on')->orderBy('stock_receipt_lines.id')
            ->get(['stock_receipt_lines.item_id', 'stock_receipt_lines.unit_cost'])
            ->groupBy('item_id')->map(fn ($g) => (float) $g->last()->unit_cost);

        $last = $history->all();
        $changes = collect();
        foreach ($lines as $l) {
            $cost = (float) $l->unit_cost;
            $prev = $last[$l->item_id] ?? null;
            if ($prev !== null && $prev > 0 && abs($cost - $prev) > 0.00001) {
                $pct = ($cost - $prev) / $prev * 100;
                $changes->push((object) ['line' => $l, 'prev' => $prev, 'cost' => $cost, 'pct' => $pct, 'flag' => $pct > $jump,
                    'extra' => max(0, $cost - $prev) * (float) $l->quantity]);
            }
            $last[$l->item_id] = $cost;
        }

        $ordered = (float) $suppliers->sum('ordered');

        return [
            'suppliers' => $suppliers,
            'changes'   => $changes->sortByDesc('pct')->values(),
            'jump'      => $jump,
            'totals'    => [
                'spend'     => (float) $suppliers->sum('spend'),
                'receipts'  => $receipts->count(),
                'rejected'  => (float) $suppliers->sum('rejected'),
                'fill'      => $ordered > 0 ? (float) $suppliers->sum('received') / $ordered * 100 : null,
                'rises'     => $changes->where('pct', '>', 0)->count(),
                'flagged'   => $changes->where('flag', true)->count(),
                'extra'     => (float) $changes->sum('extra'),
            ],
        ];
    }

    /* ===================================================================== */

    /** Guest-bill charges in the period, except bar sales charged to rooms (counted as POS). */
    private function billCharges(Carbon $from, Carbon $to): Collection
    {
        return FolioCharge::whereNull('voided_at')
            ->whereBetween('service_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', (new PosOrder)->getMorphClass()))
            ->get(['type', 'total', 'tax_breakdown', 'service_date']);
    }

    /** Tax included in a set of charges or sales, from their snapshots. */
    private function taxOf(Collection $rows): float
    {
        return (float) $rows->sum(fn ($r) => collect($r->tax_breakdown ?? [])->sum('amount'));
    }

    private function olderThan($date, int $days): bool
    {
        return $date && Carbon::parse($date)->lt(now()->subDays($days));
    }
}
