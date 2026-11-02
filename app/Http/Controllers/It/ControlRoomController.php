<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use App\Models\It\ItAlert;
use App\Models\It\ItAsset;
use App\Models\It\ItMaintenanceWindow;
use App\Models\It\ItMonitor;
use App\Models\It\ItTicket;
use App\Services\It\MonitorService;
use App\Services\It\ServiceReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The IT operations centre dashboard (spec §11.5) and monthly service report. */
class ControlRoomController extends Controller
{
    public function __construct(private MonitorService $monitors)
    {
        $this->middleware('permission:View IT control room|Manage IT monitoring|Manage IT tickets');
        $this->middleware('permission:Manage IT monitoring')->only('runChecks');
    }

    public function index(Request $request)
    {
        $monitors = ItMonitor::ordered()->with('asset:id,name')->get();
        $windows = ItMaintenanceWindow::now()->get();
        $ids = $monitors->pluck('id')->all();
        $uptime24 = $this->monitors->uptime(now()->subDay());
        $uptime30 = $this->monitors->uptime(now()->subDays(30));
        $recent = $this->monitors->recent($ids, 30);

        $categories = collect(ItMonitor::CATEGORIES)->map(function ($c, $key) use ($monitors) {
            $g = $monitors->where('category', $key)->where('is_active', true);

            return (object) ['label' => $c[0], 'icon' => $c[1], 'total' => $g->count(),
                'up' => $g->where('status', 'up')->count(), 'degraded' => $g->where('status', 'degraded')->count(),
                'down' => $g->where('status', 'down')->count(), 'other' => $g->whereIn('status', ['unknown', 'maintenance'])->count()];
        });

        $alerts = ItAlert::active()->with(['monitor:id,name', 'acknowledgedBy:id,name', 'ticket:id,number'])
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->latest('opened_at')->get();
        $activeTickets = ItTicket::active()->get(['id', 'priority', 'assigned_to', 'response_due_at', 'resolve_due_at', 'first_response_at', 'escalated_at']);
        $queue = ItTicket::active()->with(['requester:id,name', 'assignee:id,name'])
            ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->oldest()->limit(8)->get();

        $lastRun = $this->monitors->lastRun();
        $stats = [
            'up'        => $monitors->where('is_active', true)->whereIn('status', ['up', 'degraded'])->count(),
            'active'    => $monitors->where('is_active', true)->count(),
            'down'      => $monitors->where('is_active', true)->where('status', 'down')->count(),
            'alerts'    => $alerts->count(),
            'unacked'   => $alerts->where('status', 'open')->count(),
            'tickets'   => $activeTickets->count(),
            'overdue'   => ItTicket::overdue()->count(),
            'unassigned' => $activeTickets->whereNull('assigned_to')->count(),
            'uptime'    => $uptime30 ? round(collect($uptime30)->avg(), 2) : null,
            'warranty'  => ItAsset::where('is_active', true)->whereNotNull('warranty_until')->whereBetween('warranty_until', [today(), today()->addDays(60)])->count(),
        ];
        $maintenance = ItMaintenanceWindow::upcoming()->orderBy('starts_at')->limit(5)->get();
        $stale = ! $lastRun || $lastRun->lt(now()->subMinutes(10));
        $pagetitle = 'IT Control Room';

        return view('it.control-room', compact('pagetitle', 'monitors', 'windows', 'uptime24', 'uptime30', 'recent', 'categories', 'alerts', 'queue', 'stats', 'maintenance', 'lastRun', 'stale'));
    }

    /** "Check now": run every active monitor straight away. */
    public function runChecks()
    {
        $n = $this->monitors->runDue(true);

        return back()->with('success', "{$n} monitor(s) checked.");
    }

    public function report(Request $request, ServiceReport $service)
    {
        try {
            $month = $request->query('month') ? Carbon::createFromFormat('!Y-m', $request->query('month'))->startOfMonth() : today()->startOfMonth();
        } catch (\Throwable) {
            $month = today()->startOfMonth();
        }
        $month = $month->min(today()->startOfMonth());
        $report = $service->month($month);

        if ($request->query('export') === 'csv') {
            return response()->streamDownload(function () use ($report) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Monitor', 'Type', 'Uptime %', 'Alerts', 'Downtime (minutes)']);
                foreach ($report['availability'] as $a) {
                    fputcsv($out, [$a->monitor->name, $a->monitor->type_label, $a->uptime ?? '', $a->alerts, $a->downtime]);
                }
                fputcsv($out, []);
                fputcsv($out, ['Priority', 'Tickets', 'Avg response (min)', 'Avg fix (hours)', 'Missed a target']);
                foreach ($report['by_priority'] as $p) {
                    fputcsv($out, [$p->label, $p->count, $p->response !== null ? round($p->response) : '', $p->resolve !== null ? round($p->resolve, 1) : '', $p->late]);
                }
                fclose($out);
            }, 'it-service-report-' . $month->format('Y-m') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $pagetitle = 'IT Service Report';

        return view('it.report', compact('pagetitle', 'report', 'month'));
    }
}
