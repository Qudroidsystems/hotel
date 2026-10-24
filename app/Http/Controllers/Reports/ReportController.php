<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ManagementReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Management dashboard and reports (spec §13): occupancy, ADR, RevPAR, revenue by department, money in vs owed. */
class ReportController extends Controller
{
    public function __construct(private ManagementReportService $reports)
    {
        $this->middleware('permission:View management reports');
    }

    public function index(Request $request)
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->build($from, $to);

        if (in_array($request->query('export'), ['daily', 'departments'], true)) {
            return $this->csv($request->query('export'), $report, $from, $to);
        }

        $previous = $this->reports->previous($from, $to);
        $pagetitle = 'Management Reports';

        return view('reports.index', compact('pagetitle', 'report', 'previous', 'from', 'to'));
    }

    /** ?period=today|week|month|last_month|year, or explicit from/to. Defaults to this month so far. */
    private function range(Request $request): array
    {
        $today = today();
        [$from, $to] = match ($request->query('period')) {
            'today'      => [$today, $today],
            'yesterday'  => [$today->copy()->subDay(), $today->copy()->subDay()],
            'week'       => [$today->copy()->startOfWeek(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'year'       => [$today->copy()->startOfYear(), $today],
            default      => [$this->date($request->query('from'), $today->copy()->startOfMonth()), $this->date($request->query('to'), $today)],
        };
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        // Keep a single page sensible: at most a year and a bit.
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

    private function csv(string $which, array $report, Carbon $from, Carbon $to): StreamedResponse
    {
        $name = "{$which}-report-{$from->toDateString()}-to-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($which, $report) {
            $out = fopen('php://output', 'w');
            if ($which === 'daily') {
                fputcsv($out, ['Date', 'Rooms sold', 'Occupancy %', 'Room revenue', 'Bar & restaurant', 'Collected']);
                foreach ($report['daily'] as $d) {
                    fputcsv($out, [$d['date']->toDateString(), $d['sold'], $d['occupancy'], $d['room'], $d['pos'], $d['collected']]);
                }
            } else {
                fputcsv($out, ['Source', 'Department / outlet', 'Lines or sales', 'Revenue (incl. tax)', 'Of which tax']);
                foreach ($report['departments']->concat($report['outlets']) as $row) {
                    fputcsv($out, [$row['source'], $row['label'], $row['count'], $row['amount'], $row['tax']]);
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
