<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Inventory\StockCategory;
use App\Models\Inventory\StockLocation;
use App\Services\Reports\AdvancedReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Advanced financial and inventory reports (Release 2). Financial reports need
 * "View finance reports" or "View management reports"; inventory reports need
 * "View stock reports" or "View management reports". Every report can be
 * downloaded as CSV (opens in Excel) or printed / saved as PDF from the browser.
 */
class AdvancedReportController extends Controller
{
    public const REPORTS = [
        // key => [title, icon, area, description]
        'profit'     => ['Profit & loss', 'ri-funds-line', 'finance', 'Revenue, cost of sales, running costs and net operating profit'],
        'trend'      => ['12-month trend', 'ri-line-chart-line', 'finance', 'Month by month occupancy, ADR, RevPAR, revenue and money in'],
        'tax'        => ['Tax report', 'ri-percent-line', 'finance', 'VAT, service charge and other taxes charged'],
        'ageing'     => ['Debtors & creditors', 'ri-time-line', 'finance', 'Who owes the hotel and whom it owes, by age'],
        'exceptions' => ['Discounts, voids & cash', 'ri-alarm-warning-line', 'finance', 'Discounts, voids, refunds and cash differences by staff member'],
        'stock'      => ['Stock health & reorder', 'ri-heart-pulse-line', 'stock', 'Usage, days of cover, what to reorder, slow-moving stock'],
        'variance'   => ['Stocktake variance', 'ri-list-check-2', 'stock', 'Differences found at stocktakes and items often short'],
        'purchasing' => ['Purchasing & suppliers', 'ri-truck-line', 'stock', 'Spend, delivery fill rate and price changes by supplier'],
        'daily'      => ['Daily figures', 'ri-moon-clear-line', 'sales', 'Each day closed by the night audit: occupancy, rates, revenue, money in'],
        'rates'      => ['Rates & promo codes', 'ri-price-tag-3-line', 'sales', 'Stays by rate plan, promo code and where the booking came from'],
        'companies'  => ['Company accounts', 'ri-building-2-line', 'sales', 'Stays, billing, payments and what each company owes'],
        'events'     => ['Events & halls', 'ri-presentation-line', 'sales', 'Event revenue, how busy each hall was, enquiries that became bookings'],
    ];

    public function __construct(private AdvancedReportService $reports, private \App\Services\Reports\SalesReportService $sales)
    {
        $this->middleware('permission:View management reports|View finance reports|View stock reports');
    }

    public function index(Request $request)
    {
        $allowed = collect(self::REPORTS)->filter(fn ($r) => $this->can($request, $r[2]));
        abort_if($allowed->isEmpty(), 403);
        $tab = $allowed->has($request->query('report')) ? $request->query('report') : $allowed->keys()->first();

        [$from, $to] = $this->range($request, $tab);
        $locationId = $request->integer('location') ?: null;
        $categoryId = $request->integer('category') ?: null;

        $data = match ($tab) {
            'profit'     => $this->reports->profit($from, $to),
            'trend'      => $this->reports->trend($to),
            'tax'        => $this->reports->taxReport($from, $to),
            'ageing'     => $this->reports->ageing(),
            'exceptions' => $this->reports->exceptions($from, $to),
            'stock'      => $this->reports->stockHealth($locationId, $categoryId),
            'variance'   => $this->reports->variance($from, $to, $locationId),
            'purchasing' => $this->reports->purchasing($from, $to),
            'daily'      => $this->sales->daily($from, $to),
            'rates'      => $this->sales->rates($from, $to),
            'companies'  => $this->sales->companies($from, $to),
            'events'     => $this->sales->events($from, $to),
        };

        if ($request->query('export') === 'csv') {
            return $this->csv($tab, $data, $from, $to, $request);
        }

        $reports = $allowed;
        $locations = in_array($tab, ['stock', 'variance'], true) ? StockLocation::ordered()->get() : collect();
        $categories = $tab === 'stock' ? StockCategory::ordered()->get() : collect();
        $pagetitle = self::REPORTS[$tab][0];

        return view('reports.advanced', compact('pagetitle', 'reports', 'tab', 'data', 'from', 'to', 'locationId', 'categoryId', 'locations', 'categories'));
    }

    private function can(Request $request, string $area): bool
    {
        $u = $request->user();

        return $u->can('View management reports') || $u->can($area === 'stock' ? 'View stock reports' : 'View finance reports');
    }

    /** ?period=… or from/to. Defaults: this month so far; the trend ends this month. */
    private function range(Request $request, string $tab): array
    {
        $today = today();
        [$from, $to] = match ($request->query('period')) {
            'today'      => [$today, $today],
            'week'       => [$today->copy()->startOfWeek(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'quarter'    => [$today->copy()->startOfQuarter(), $today],
            'year'       => [$today->copy()->startOfYear(), $today],
            default      => [$this->date($request->query('from'), $today->copy()->startOfMonth()), $this->date($request->query('to'), $today)],
        };
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 400) {
            $from = $to->copy()->subDays(400);
        }

        return [$from->copy()->startOfDay(), $to->copy()->startOfDay()];
    }

    private function date($value, Carbon $fallback): Carbon
    {
        try {
            return $value ? Carbon::parse($value) : $fallback->copy();
        } catch (\Throwable) {
            return $fallback->copy();
        }
    }

    private function csv(string $tab, array $d, Carbon $from, Carbon $to, Request $request): StreamedResponse
    {
        $r2 = fn ($v) => $v === null ? '' : round((float) $v, 2) + 0;   // "+ 0" turns -0 into 0
        [$head, $rows] = match ($tab) {
            'profit' => [['Line', 'Sales incl. tax', 'Tax', 'Net revenue', 'Cost of sales', 'Gross profit', 'Margin %'],
                collect($d['lines'])->map(fn ($l) => [$l['label'], $r2($l['gross']), $r2($l['tax']), $r2($l['net']), $r2($l['cost']), $r2($l['net'] - $l['cost']), $l['net'] > 0 ? $r2(($l['net'] - $l['cost']) / $l['net'] * 100) : ''])
                    ->concat(collect($d['outlets'])->map(fn ($o) => ['  Outlet: ' . $o['label'], $r2($o['gross']), $r2($o['tax']), $r2($o['net']), $r2($o['cost']), $r2($o['margin']), $r2($o['pct'])]))
                    ->concat([['Stock used in-house', '', '', '', $r2($d['losses']['issue']), '', ''], ['Waste & breakage', '', '', '', $r2($d['losses']['waste']), '', ''],
                        ['Stocktake differences', '', '', '', $r2($d['losses']['count']), '', ''], ['Adjustments', '', '', '', $r2($d['losses']['adjustment']), '', ''],
                        ['Gross profit after stock losses', '', '', '', '', $r2($d['totals']['after_stock']), '']])
                    ->concat(collect($d['opex'])->flatMap(fn ($g) => collect([['Running costs: ' . $g->label, '', '', '', $r2($g->amount), '', '']])
                        ->concat($g->categories->map(fn ($c) => ['  ' . $c->name, '', '', '', $r2($c->amount), '', '']))))
                    ->concat([['Total running costs', '', '', '', $r2($d['totals']['opex']), '', ''],
                        ['NET OPERATING PROFIT', '', '', '', '', $r2($d['totals']['net_profit']), $r2($d['totals']['np_pct'])]])],
            'trend' => [['Month', 'Rooms sold', 'Occupancy %', 'ADR', 'RevPAR', 'Room revenue', 'Bar & restaurant', 'Other', 'Total revenue', 'Tax', 'Cost of sales', 'Gross profit', 'Running costs', 'Net operating profit', 'Collected'],
                $d['rows']->map(fn ($m) => [$m['month']->format('Y-m'), $m['sold'], $m['occupancy'], $r2($m['adr']), $r2($m['revpar']), $r2($m['room']), $r2($m['pos']), $r2($m['other']), $r2($m['revenue']), $r2($m['tax']), $r2($m['cogs']), $r2($m['gross']), $r2($m['opex']), $r2($m['net']), $r2($m['collected'])])],
            'tax' => [['Tax', 'Rate %', 'On guest bills', 'On bar & restaurant', 'Total tax', 'Sales it applied to'],
                $d['by_tax']->map(fn ($t) => [$t['name'], $t['rate'], $r2($t['bills']), $r2($t['pos']), $r2($t['amount']), $r2($t['base'])])],
            'ageing' => $request->query('part') === 'suppliers'
                ? [['Supplier', 'Invoices', 'Not yet due', '1-30 days late', '31-60', '61-90', 'Over 90', 'Total owed'],
                    $d['suppliers']->map(fn ($s) => [$s['supplier']?->name, $s['invoices'], $r2($s['current']), $r2($s['d30']), $r2($s['d60']), $r2($s['d90']), $r2($s['over']), $r2($s['total'])])]
                : [['Bill', 'Guest', 'Booking', 'Status', 'Days since departure', 'Age band', 'Balance owed'],
                    $d['guests']->map(fn ($g) => [$g['folio']->number, $g['folio']->guest?->full_name, $g['folio']->reservation?->reference, $g['in_house'] ? 'In house' : 'Departed', $g['in_house'] ? '' : $g['days'], $d['buckets'][$g['bucket']], $r2($g['balance'])])],
            'exceptions' => [['Staff member', 'Bar discounts', 'Bar voids', 'Bill discounts', 'Bill charge voids', 'Refunds', 'Payment voids', 'Shifts closed', 'Cash short', 'Cash over'],
                $d['rows']->map(fn ($r) => [$r['name'], $r2($r['pos_discount']), $r2($r['pos_void']), $r2($r['bill_discount']), $r2($r['charge_void']), $r2($r['refund']), $r2($r['payment_void']), $r['shifts'], $r2($r['cash_short']), $r2($r['cash_over'])])],
            'stock' => [['Item', 'SKU', 'Category', 'Unit', 'On hand', 'Value', 'Used in period', 'Per day', 'Days of cover', 'Last used', 'Status', 'Suggested order', 'Order value'],
                $d['rows']->map(fn ($r) => [$r->item->name, $r->item->sku, $r->item->category?->name, $r->item->unit, $r->qty, $r2($r->value), $r->usage, round($r->daily, 3), $r->cover === null ? '' : round($r->cover, 1), $r->last_used?->toDateString(), $d['statuses'][$r->status][0] ?? $r->status, $r->suggest ?: '', $r->suggest ? $r2($r->suggest_value) : ''])],
            'variance' => [['Item', 'Unit', 'Stocktakes with a difference', 'Net quantity difference', 'Net value difference'],
                $d['items']->map(fn ($i) => [$i->item?->name, $i->item?->unit, $i->times, $i->qty, $r2($i->value)])],
            'purchasing' => $request->query('part') === 'prices'
                ? [['Date', 'Receipt', 'Supplier', 'Item', 'Quantity', 'Previous cost', 'New cost', 'Change %', 'Extra paid'],
                    $d['changes']->map(fn ($c) => [$c->line->receipt->received_on->toDateString(), $c->line->receipt->number, $c->line->receipt->supplier?->name, $c->line->item?->name, (float) $c->line->quantity, $r2($c->prev), $r2($c->cost), $r2($c->pct), $r2($c->extra)])]
                : [['Supplier', 'Deliveries', 'Spend', 'Ordered value', 'Received of ordered', 'Fill rate %', 'Rejected value', 'On time %'],
                    $d['suppliers']->map(fn ($s) => [$s['supplier']?->name ?? 'No supplier', $s['receipts'], $r2($s['spend']), $r2($s['ordered']), $r2($s['received']), $r2($s['fill']), $r2($s['rejected']), $r2($s['on_time'])])],
            'daily' => [['Day', 'Rooms sold', 'Rooms for sale', 'Occupancy %', 'ADR', 'RevPAR', 'Room revenue', 'Bar & restaurant', 'Events', 'Total revenue', 'Money in', 'Arrivals', 'Departures', 'No-shows'],
                $d['days']->map(fn ($a) => [$a->business_date->toDateString(), data_get($a->report, 'rooms.sold'), data_get($a->report, 'rooms.sellable'), data_get($a->report, 'rooms.occupancy'), $r2(data_get($a->report, 'rooms.adr')), $r2(data_get($a->report, 'rooms.revpar')),
                    $r2(data_get($a->report, 'rooms.revenue')), $r2(data_get($a->report, 'revenue.pos.sales')), $r2(data_get($a->report, 'revenue.events.revenue')), $r2(data_get($a->report, 'revenue.total')), $r2(data_get($a->report, 'collections.total')),
                    data_get($a->report, 'guests.arrivals'), data_get($a->report, 'guests.departures'), data_get($a->report, 'guests.no_shows')])],
            'rates' => [['Group', 'Rate plan / code / source', 'Stays', 'Room nights', 'Revenue', 'Average a night', 'Cancelled / no-show'],
                collect(['Rate plan' => $d['by_plan'], 'Promo code' => $d['by_promo'], 'Source' => $d['by_source'], 'Booking site' => $d['by_channel']])->flatMap(fn ($rows, $g) => $rows->map(fn ($x) => [$g, $x['label'], $x['bookings'], $x['nights'], $r2($x['revenue']), $r2($x['avg']), $x['lost']]))],
            'companies' => [['Company', 'Account', 'Stays', 'Room nights', 'Events', 'Billed', 'Paid', 'Owes now', 'Overdue now', 'Credit limit'],
                $d['rows']->map(fn ($x) => [$x['company']->name, $x['company']->account_number, $x['stays'], $x['nights'], $x['events'], $r2($x['billed']), $r2($x['paid']), $r2($x['owes']), $r2($x['overdue']), $r2($x['limit'])])],
            'events' => [['Date', 'Event', 'Number', 'Hall', 'Kind', 'Status', 'Guests', 'Total'],
                $d['list']->map(fn ($e) => [$e->starts_at->format('Y-m-d H:i'), $e->title, $e->number, $e->hall?->name, $e->type_label, $e->status_label, $e->guests_expected, $r2($e->total)])],
        };

        $suffix = in_array($tab, ['ageing', 'stock'], true) ? today()->toDateString() : $from->toDateString() . '-to-' . $to->toDateString();
        $name = str_replace('_', '-', $tab) . ($request->query('part') ? '-' . $request->query('part') : '') . '-' . $suffix . '.csv';

        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₦ correctly
            fputcsv($out, $head);
            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
