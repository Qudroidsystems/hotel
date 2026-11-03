<?php

namespace App\Services\Reports;

use App\Models\MaintenanceOrder;
use App\Models\MaintenancePlan;
use App\Services\Booking\FeedbackService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The management report by e-mail (Hotel Settings → Report E-mails): last
 * week every Monday, last month on the 1st. Same figures as Reports →
 * Management Report, with guest feedback and repairs added. The daily report
 * goes out with the night audit.
 */
class ReportEmails
{
    public function __construct(private ManagementReportService $reports, private FeedbackService $feedback)
    {
    }

    /** [from, to] of the last full week (Mon–Sun) or month before $today. */
    public function period(string $kind, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();

        return $kind === 'month'
            ? [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()]
            : [$today->copy()->subWeek()->startOfWeek(Carbon::MONDAY), $today->copy()->subWeek()->endOfWeek(Carbon::SUNDAY)->startOfDay()];
    }

    public function recipients(string $kind): array
    {
        return array_values(array_filter((array) config($kind === 'month' ? 'hotel.reports.monthly_emails' : 'hotel.reports.weekly_emails', [])));
    }

    /** Everything the e-mail shows. */
    public function build(string $kind, ?Carbon $today = null): array
    {
        [$from, $to] = $this->period($kind, $today);
        $active = MaintenancePlan::where('active', true)->get(['id', 'next_due_on', 'active']);

        return [
            'kind'     => $kind,
            'from'     => $from,
            'to'       => $to,
            'r'        => $this->reports->build($from, $to),
            'prev'     => $this->reports->previous($from, $to),
            'feedback' => $this->feedback->summary($from, $to),
            'energy'   => rescue(fn () => app(\App\Services\Energy\EnergyReportService::class)->summary($from, $to), null, false),
            'repairs'  => [
                'open'          => MaintenanceOrder::active()->count(),
                'overdue'       => MaintenanceOrder::active()->where('due_at', '<', now())->count(),
                'fixed'         => MaintenanceOrder::where('status', MaintenanceOrder::COMPLETED)->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
                'cost'          => (float) MaintenanceOrder::where('status', MaintenanceOrder::COMPLETED)->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->sum('cost'),
                'plans_overdue' => $active->filter->isOverdue()->count(),
            ],
        ];
    }

    /** Send it. Answers how many people it went to. */
    public function send(string $kind, ?Carbon $today = null): int
    {
        $to = $this->recipients($kind);
        if (! $to) {
            return 0;
        }
        $data = $this->build($kind, $today);
        $label = $kind === 'month' ? $data['from']->format('F Y') : 'week of ' . $data['from']->format('j M') . ' – ' . $data['to']->format('j M Y');
        try {
            Mail::send('reports.email', $data, fn ($m) => $m->to($to)->subject(config('hotel.name') . ' — ' . ($kind === 'month' ? 'monthly' : 'weekly') . " report, {$label}"));
        } catch (Throwable $e) {
            Log::warning("The {$kind}ly report was not e-mailed: " . $e->getMessage());

            return 0;
        }

        return count($to);
    }
}
