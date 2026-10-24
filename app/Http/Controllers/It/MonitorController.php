<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use App\Models\It\ItAsset;
use App\Models\It\ItCheck;
use App\Models\It\ItMonitor;
use App\Services\It\MonitorService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** What the control room watches, and each monitor's history. */
class MonitorController extends Controller
{
    public function __construct(private MonitorService $service)
    {
        $this->middleware('permission:View IT control room|Manage IT monitoring');
        $this->middleware('permission:Manage IT monitoring')->except(['index', 'show']);
    }

    public function index()
    {
        $monitors = ItMonitor::ordered()->with('asset:id,name')->get();
        $uptime = $this->service->uptime(now()->subDays(7));
        $assets = ItAsset::where('is_active', true)->orderBy('name')->get(['id', 'name', 'ip_address']);
        $pagetitle = 'Monitors';

        return view('it.monitors.index', compact('pagetitle', 'monitors', 'uptime', 'assets'));
    }

    public function show(Request $request, ItMonitor $monitor)
    {
        $monitor->load('asset');
        $days = in_array((int) $request->query('days'), [1, 7, 30, 90], true) ? (int) $request->query('days') : 7;
        $since = now()->subDays($days);
        $checks = ItCheck::where('monitor_id', $monitor->id)->where('checked_at', '>=', $since)->orderBy('checked_at')->get(['status', 'latency_ms', 'message', 'checked_at']);

        // Group into up to 96 slots for the history strip; a slot shows its worst status.
        $slots = 96;
        $size = max(1, (int) ceil($days * 1440 / $slots));
        $order = ['down' => 0, 'degraded' => 1, 'maintenance' => 2, 'up' => 3, 'unknown' => 4];
        $strip = [];
        foreach ($checks as $c) {
            $i = (int) floor($since->diffInMinutes($c->checked_at) / $size);
            if (! isset($strip[$i]) || $order[$c->status] < $order[$strip[$i]['status']]) {
                $strip[$i] = ['status' => $c->status, 'at' => $c->checked_at, 'message' => $c->message];
            }
        }
        $latency = $checks->whereNotNull('latency_ms');
        $stats = [
            'uptime'  => $this->service->uptime($since, null, [$monitor->id])[$monitor->id] ?? null,
            'checks'  => $checks->count(),
            'avg_ms'  => $latency->count() ? (int) round($latency->avg('latency_ms')) : null,
            'max_ms'  => $latency->max('latency_ms'),
            'failed'  => $checks->where('status', 'down')->count(),
        ];
        $problems = $checks->whereIn('status', ['down', 'degraded'])->sortByDesc('checked_at')->take(30);
        $alerts = $monitor->alerts()->latest('opened_at')->limit(15)->get();
        $pagetitle = $monitor->name;

        return view('it.monitors.show', compact('pagetitle', 'monitor', 'days', 'strip', 'slots', 'size', 'since', 'stats', 'problems', 'alerts'));
    }

    public function store(Request $request)
    {
        $monitor = ItMonitor::create($this->validated($request));
        Audit::record('it.monitor_added', "Added monitor {$monitor->name} ({$monitor->type_label}: {$monitor->target_label})", $monitor);
        $this->service->check($monitor);

        return redirect()->route('it.monitors.index')->with('success', "{$monitor->name} added and checked: " . strtolower($monitor->fresh()->status_label) . '.');
    }

    public function update(Request $request, ItMonitor $monitor)
    {
        $monitor->fill($this->validated($request, $monitor));
        [$old, $new] = Audit::diff($monitor);
        $monitor->save();
        if ($new) {
            Audit::record('it.monitor_changed', "Changed monitor {$monitor->name}", $monitor, $old, $new);
        }

        return back()->with('success', "{$monitor->name} saved.");
    }

    public function destroy(ItMonitor $monitor)
    {
        Audit::record('it.monitor_removed', "Removed monitor {$monitor->name} and its history", $monitor);
        $monitor->delete();

        return redirect()->route('it.monitors.index')->with('success', 'Monitor removed.');
    }

    public function check(ItMonitor $monitor)
    {
        $c = $this->service->check($monitor);

        return back()->with($c->status === 'up' ? 'success' : 'error', "{$monitor->name}: " . (ItMonitor::STATUSES[$c->status][0] ?? $c->status) . ' — ' . $c->message);
    }

    public function toggle(ItMonitor $monitor)
    {
        $monitor->forceFill(['is_active' => ! $monitor->is_active])->save();
        Audit::record('it.monitor_' . ($monitor->is_active ? 'resumed' : 'paused'), ($monitor->is_active ? 'Resumed' : 'Paused') . " monitor {$monitor->name}", $monitor);

        return back()->with('success', $monitor->name . ($monitor->is_active ? ' resumed.' : ' paused — it will not be checked or raise alerts.'));
    }

    private function validated(Request $request, ?ItMonitor $monitor = null): array
    {
        $type = (string) $request->input('type');
        $needsTarget = in_array($type, ['http', 'ping', 'tcp', 'ssl', 'backup'], true);
        $v = validator($request->all(), [
            'name'             => 'required|string|max:120',
            'category'         => ['required', Rule::in(array_keys(ItMonitor::CATEGORIES))],
            'type'             => ['required', Rule::in(array_keys(ItMonitor::TYPES))],
            'target'           => [$needsTarget ? 'required' : 'nullable', 'string', 'max:500'],
            'port'             => [$type === 'tcp' ? 'required' : 'nullable', 'integer', 'min:1', 'max:65535'],
            'asset_id'         => 'nullable|integer|exists:it_assets,id',
            'severity'         => ['required', Rule::in(['critical', 'high', 'normal', 'low'])],
            'interval_minutes' => 'required|integer|min:1|max:1440',
            'timeout_seconds'  => 'required|integer|min:1|max:60',
            'alert_after'      => 'required|integer|min:1|max:20',
            'expect_status'    => 'nullable|integer|min:100|max:599',
            'keyword'          => 'nullable|string|max:120',
            'threshold'        => 'nullable|numeric|min:0|max:1000000',
            'sort_order'       => 'nullable|integer|min:0|max:999',
        ], [], ['target' => 'address / folder', 'alert_after' => 'failed checks before an alert']);

        $v->after(function (Validator $v) use ($request, $type) {
            $target = trim((string) $request->input('target'));
            if ($type === 'http' && $target !== '' && ! filter_var($target, FILTER_VALIDATE_URL)) {
                $v->errors()->add('target', 'Enter a full web address starting with http:// or https://');
            }
            if (in_array($type, ['ping', 'tcp'], true) && $target !== '' && ! MonitorService::validHost($target)) {
                $v->errors()->add('target', 'Enter an IP address (e.g. 192.168.1.1) or a host name.');
            }
            if ($type === 'ssl' && $target !== '' && ! MonitorService::validHost(explode('/', preg_replace('#^https?://#i', '', $target))[0])) {
                $v->errors()->add('target', 'Enter a domain name, e.g. smarthotel.com');
            }
        });
        $data = $v->validate();
        $data['target'] = isset($data['target']) ? trim($data['target']) : null;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $monitor ? $request->boolean('is_active') : true;

        return $data;
    }
}
