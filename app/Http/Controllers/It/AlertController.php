<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use App\Models\It\ItAlert;
use App\Models\It\ItTicket;
use App\Services\It\ServiceDesk;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Monitoring alerts: acknowledge ("I'm on it"), resolve, or turn into a ticket. */
class AlertController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View IT control room|Manage IT monitoring|Manage IT tickets');
        $this->middleware('permission:Manage IT monitoring|Manage IT tickets')->except('index');
    }

    public function index(Request $request)
    {
        $show = in_array($request->query('show'), ['active', 'resolved', 'all'], true) ? $request->query('show') : 'active';
        $alerts = ItAlert::with(['monitor:id,name,type', 'acknowledgedBy:id,name', 'resolvedBy:id,name', 'ticket:id,number'])
            ->when($show === 'active', fn ($q) => $q->active())
            ->when($show === 'resolved', fn ($q) => $q->where('status', 'resolved'))
            ->orderByRaw("case status when 'open' then 0 when 'acknowledged' then 1 else 2 end")
            ->latest('opened_at')->paginate(40)->withQueryString();
        $week = ItAlert::where('opened_at', '>=', now()->subDays(7))->get(['id', 'opened_at', 'resolved_at', 'auto_resolved']);
        $cleared = $week->whereNotNull('resolved_at');
        $stats = [
            'open'  => ItAlert::where('status', 'open')->count(),
            'acked' => ItAlert::where('status', 'acknowledged')->count(),
            'week'  => $week->count(),
            'auto'  => $week->where('auto_resolved', true)->count(),
            'mttr'  => $cleared->count() ? (int) round($cleared->avg(fn ($a) => $a->opened_at->diffInMinutes($a->resolved_at))) : null,
        ];
        $pagetitle = 'IT Alerts';

        return view('it.alerts', compact('pagetitle', 'alerts', 'show', 'stats'));
    }

    public function acknowledge(Request $request, ItAlert $alert)
    {
        if ($alert->status === 'open') {
            $alert->forceFill(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id])->save();
            Audit::record('it.alert_acknowledged', "{$alert->number} acknowledged: {$alert->title}", $alert);
        }

        return back()->with('success', "{$alert->number} acknowledged — others can see you are on it.");
    }

    public function resolve(Request $request, ItAlert $alert)
    {
        $data = $request->validate(['resolution' => 'required|string|max:1000']);
        if ($alert->isActive()) {
            $alert->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $request->user()->id, 'resolution' => $data['resolution'],
                'acknowledged_at' => $alert->acknowledged_at ?? now(), 'acknowledged_by' => $alert->acknowledged_by ?? $request->user()->id])->save();
            Audit::record('it.alert_resolved', "{$alert->number} resolved: {$data['resolution']}", $alert);
        }

        return back()->with('success', "{$alert->number} resolved. If the check still fails, a new alert will open.");
    }

    public function ticket(Request $request, ItAlert $alert, ServiceDesk $desk)
    {
        if ($alert->ticket_id) {
            return redirect()->route('it.tickets.show', $alert->ticket_id);
        }
        $alert->loadMissing('monitor.asset');
        $ticket = $desk->open([
            'title'       => str($alert->title)->limit(150)->toString(),
            'description' => trim(($alert->message ?? '') . "\n\nOpened " . $alert->opened_at->format('D j M Y, g:i A') . " by the monitoring system ({$alert->number})."),
            'category'    => match ($alert->monitor?->type) { 'http', 'ssl', 'database', 'queue' => 'software', 'ping', 'tcp' => 'internet', default => 'other' },
            'priority'    => array_key_exists($alert->severity, ItTicket::PRIORITIES) ? $alert->severity : 'high',
            'department'  => 'IT',
            'location'    => $alert->monitor?->asset?->location,
            'asset_id'    => $alert->monitor?->asset_id,
            'assigned_to' => $request->user()->id,
        ], $request->user(), $alert);
        if ($alert->status === 'open') {
            $alert->forceFill(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id])->save();
        }

        return redirect()->route('it.tickets.show', $ticket)->with('success', "{$ticket->number} opened from {$alert->number} and assigned to you.");
    }
}
