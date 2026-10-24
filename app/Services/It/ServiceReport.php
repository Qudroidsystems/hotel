<?php

namespace App\Services\It;

use App\Models\It\ItAlert;
use App\Models\It\ItMonitor;
use App\Models\It\ItTicket;
use Illuminate\Support\Carbon;

/**
 * Monthly IT service performance (spec §11.4, §22): measured uptime, alerts
 * and how fast they were cleared, and how the service desk met its targets.
 */
class ServiceReport
{
    public function __construct(private MonitorService $monitors)
    {
    }

    public function month(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $until = $end->copy()->min(now());

        // ── Availability ─────────────────────────────────────────────
        $monitors = ItMonitor::ordered()->get();
        $uptime = $this->monitors->uptime($start, $end->copy()->addSecond());
        $alerts = ItAlert::with('monitor:id,name')->whereBetween('opened_at', [$start, $end])->get();
        $downtime = $alerts->whereNotNull('monitor_id')->groupBy('monitor_id')
            ->map(fn ($g) => (int) $g->sum(fn ($a) => $a->opened_at->diffInMinutes(($a->resolved_at ?? $until)->max($a->opened_at))));
        $availability = $monitors->map(fn ($m) => (object) [
            'monitor'  => $m,
            'uptime'   => $uptime[$m->id] ?? null,
            'alerts'   => $alerts->where('monitor_id', $m->id)->count(),
            'downtime' => $downtime[$m->id] ?? 0,
        ]);
        $measured = $availability->whereNotNull('uptime');

        // ── Alerts ───────────────────────────────────────────────────
        $cleared = $alerts->whereNotNull('resolved_at');
        $acked = $alerts->whereNotNull('acknowledged_at');

        // ── Service desk ─────────────────────────────────────────────
        $tickets = ItTicket::with(['assignee:id,name'])->whereBetween('created_at', [$start, $end])->get();
        $responded = $tickets->whereNotNull('first_response_at');
        $resolved = $tickets->whereNotNull('resolved_at');
        $dueResponse = $tickets->filter(fn ($t) => $t->first_response_at || $t->response_due_at?->lt($until));
        $dueResolve = $tickets->filter(fn ($t) => $t->resolved_at || $t->resolve_due_at?->lt($until));
        $metResponse = $dueResponse->filter(fn ($t) => $t->first_response_at && $t->first_response_at->lte($t->response_due_at));
        $metResolve = $dueResolve->filter(fn ($t) => $t->resolved_at && $t->resolved_at->lte($t->resolve_due_at));

        $byPriority = collect(ItTicket::PRIORITIES)->map(function ($p, $key) use ($tickets) {
            $g = $tickets->where('priority', $key);
            $r = $g->whereNotNull('first_response_at');
            $f = $g->whereNotNull('resolved_at');

            return (object) [
                'label' => $p[0], 'pill' => $p[1], 'count' => $g->count(),
                'response' => $r->count() ? $r->avg(fn ($t) => $t->created_at->diffInMinutes($t->first_response_at)) : null,
                'resolve'  => $f->count() ? $f->avg(fn ($t) => $t->created_at->diffInMinutes($t->resolved_at)) / 60 : null,
                'late'     => $g->filter(fn ($t) => $t->responseLate() || $t->resolveLate())->count(),
            ];
        });

        $byCategory = $tickets->groupBy('category')->map(fn ($g, $c) => (object) ['label' => ItTicket::CATEGORIES[$c][0] ?? $c, 'icon' => ItTicket::CATEGORIES[$c][1] ?? 'ri-question-line', 'count' => $g->count()])
            ->sortByDesc('count')->values();
        $byDepartment = $tickets->groupBy(fn ($t) => $t->department ?: 'Not given')->map->count()->sortDesc();
        $byTechnician = $resolved->groupBy(fn ($t) => $t->assignee?->name ?? 'Unassigned')->map->count()->sortDesc();

        // Same kind of problem in the same place 3+ times → worth a root-cause look.
        $repeats = $tickets->filter(fn ($t) => $t->location)->groupBy(fn ($t) => $t->category . '|' . mb_strtolower(trim($t->location)))
            ->filter(fn ($g) => $g->count() >= 3)
            ->map(fn ($g) => (object) ['label' => $g->first()->category_label, 'location' => $g->first()->location, 'count' => $g->count(), 'root' => $g->pluck('root_cause')->filter()->last()])
            ->values();

        return [
            'start' => $start, 'end' => $end,
            'availability' => $availability,
            'overall'      => $measured->count() ? round($measured->avg('uptime'), 2) : null,
            'alerts'       => [
                'total'    => $alerts->count(),
                'critical' => $alerts->whereIn('severity', ['critical', 'high'])->count(),
                'auto'     => $alerts->where('auto_resolved', true)->count(),
                'ack_mins' => $acked->count() ? round($acked->avg(fn ($a) => $a->opened_at->diffInMinutes($a->acknowledged_at))) : null,
                'mttr'     => $cleared->count() ? round($cleared->avg(fn ($a) => $a->opened_at->diffInMinutes($a->resolved_at))) : null,
                'open'     => $alerts->where('status', '!=', 'resolved')->count(),
            ],
            'tickets'      => [
                'raised'        => $tickets->count(),
                'resolved'      => $resolved->count(),
                'open'          => $tickets->filter->isActive()->count(),
                'escalated'     => $tickets->whereNotNull('escalated_at')->count(),
                'response_mins' => $responded->count() ? round($responded->avg(fn ($t) => $t->created_at->diffInMinutes($t->first_response_at))) : null,
                'resolve_hours' => $resolved->count() ? round($resolved->avg(fn ($t) => $t->created_at->diffInMinutes($t->resolved_at)) / 60, 1) : null,
                'response_met'  => $dueResponse->count() ? round($metResponse->count() / $dueResponse->count() * 100, 1) : null,
                'resolve_met'   => $dueResolve->count() ? round($metResolve->count() / $dueResolve->count() * 100, 1) : null,
            ],
            'by_priority'   => $byPriority,
            'by_category'   => $byCategory,
            'by_department' => $byDepartment,
            'by_technician' => $byTechnician,
            'repeats'       => $repeats,
        ];
    }
}
