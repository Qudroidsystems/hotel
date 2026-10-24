<?php

namespace App\Services\It;

use App\Exceptions\BookingException;
use App\Models\It\ItAlert;
use App\Models\It\ItTicket;
use App\Models\It\ItTicketUpdate;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The IT service desk (spec §11.4). Each ticket gets a response and a fix
 * deadline from its priority (Hotel Settings → IT Service Desk). The first
 * reply or status change from IT staff counts as the response. Tickets that
 * miss a deadline are escalated (flagged on the audit trail and emailed).
 */
class ServiceDesk
{
    public function open(array $data, User $by, ?ItAlert $fromAlert = null): ItTicket
    {
        return DB::transaction(function () use ($data, $by, $fromAlert) {
            $ticket = new ItTicket([
                'title'        => $data['title'],
                'description'  => $data['description'],
                'category'     => $data['category'],
                'priority'     => $data['priority'] ?? 'normal',
                'department'   => $data['department'] ?? null,
                'location'     => $data['location'] ?? null,
                'asset_id'     => $data['asset_id'] ?? null,
                'requested_by' => $by->id,
                'assigned_to'  => $data['assigned_to'] ?? null,
            ]);
            $this->setDeadlines($ticket, now());
            $ticket->save();
            $ticket->forceFill(['number' => 'IT-' . str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->note($ticket, $by, $fromAlert ? "Raised from alert {$fromAlert->number}." : 'Raised.', 'open');

            if ($fromAlert) {
                $fromAlert->forceFill(['ticket_id' => $ticket->id])->save();
            }
            Audit::record('it.ticket_opened', "{$ticket->number} ({$ticket->priority_label}, {$ticket->category_label}): {$ticket->title}", $ticket,
                new: ['priority' => $ticket->priority], flagged: $ticket->priority === 'critical', by: $by);
            if ($ticket->priority === 'critical') {
                $this->email($ticket, "New critical IT ticket {$ticket->number}: {$ticket->title}", $ticket->description);
            }

            return $ticket;
        });
    }

    /**
     * A reply or status change. IT staff can mark notes internal (hidden from
     * the requester). The requester can reply, confirm the fix (close) or
     * reopen a resolved ticket.
     */
    public function update(ItTicket $ticket, User $by, string $note, ?string $status = null, bool $internal = false): void
    {
        $isIt = $by->can('Manage IT tickets');
        $isRequester = $by->id === $ticket->requested_by;
        if ($status !== null && ! array_key_exists($status, ItTicket::STATUSES)) {
            throw new BookingException('Unknown status.');
        }
        if ($ticket->status === 'closed' && ! $isIt) {
            throw new BookingException('This ticket is closed. Raise a new one if the problem is back.');
        }
        if (! $isIt) {
            $internal = false;
            // Requesters may only confirm a fix (close) or reopen it.
            $allowed = $ticket->status === 'resolved' ? ['closed', 'open'] : [];
            if ($status !== null && $status !== $ticket->status && ! in_array($status, $allowed, true)) {
                throw new BookingException('Only IT can change the status of this ticket.');
            }
            // A reply to "waiting on you" puts it back with IT.
            if ($status === null && $ticket->status === 'waiting') {
                $status = 'in_progress';
            }
        }
        $status = $status === $ticket->status ? null : $status;

        DB::transaction(function () use ($ticket, $by, $note, $status, $internal, $isIt, $isRequester) {
            $fill = [];
            if ($isIt && ! $isRequester && ! $ticket->first_response_at && (! $internal || $status)) {
                $fill['first_response_at'] = now();
            }
            if ($status) {
                $fill['status'] = $status;
                if ($status === 'resolved') {
                    $fill += ['resolved_at' => now(), 'resolved_by' => $by->id, 'resolution' => str($note)->limit(1995)->toString()];
                } elseif ($status === 'closed') {
                    $fill += ['closed_at' => now()] + ($ticket->resolved_at ? [] : ['resolved_at' => now(), 'resolved_by' => $by->id, 'resolution' => str($note)->limit(1995)->toString()]);
                } elseif (in_array($status, ItTicket::ACTIVE, true) && ! $ticket->isActive()) {
                    $fill += ['resolved_at' => null, 'resolved_by' => null, 'closed_at' => null];   // reopened
                }
            }
            $ticket->forceFill($fill)->save();
            $this->note($ticket, $by, $note, $status, $internal);
            if ($status) {
                Audit::record('it.ticket_status', "{$ticket->number} → " . ItTicket::STATUSES[$status][0], $ticket, new: ['status' => $status], by: $by);
            }
        });
    }

    public function assign(ItTicket $ticket, ?User $to, User $by): void
    {
        $before = $ticket->assignee?->name;
        $ticket->forceFill(['assigned_to' => $to?->id])->save();
        $this->note($ticket, $by, $to ? "Assigned to {$to->name}." : 'Unassigned.', null, true);
        Audit::record('it.ticket_assigned', "{$ticket->number} assigned to " . ($to?->name ?? 'nobody'), $ticket, old: ['assigned_to' => $before], new: ['assigned_to' => $to?->name], by: $by);
    }

    /** Change priority (and its deadlines, counted from when the ticket was raised) or record the root cause. */
    public function triage(ItTicket $ticket, User $by, string $priority, ?string $rootCause): void
    {
        $changes = [];
        if ($priority !== $ticket->priority) {
            $old = $ticket->priority_label;
            $ticket->priority = $priority;
            $this->setDeadlines($ticket, $ticket->created_at);
            $ticket->escalated_at = null;
            $changes[] = "priority {$old} → {$ticket->priority_label}";
        }
        if (($rootCause ?: null) !== $ticket->root_cause) {
            $ticket->root_cause = $rootCause ?: null;
            $changes[] = 'root cause recorded';
        }
        if (! $changes) {
            return;
        }
        $ticket->save();
        $this->note($ticket, $by, ucfirst(implode('; ', $changes)) . '.', null, true);
    }

    /** Escalate tickets that missed a deadline (scheduler, every 5 minutes). */
    public function escalateOverdue(): int
    {
        $tickets = ItTicket::overdue()->whereNull('escalated_at')->with(['assignee:id,name', 'requester:id,name'])->get();
        foreach ($tickets as $t) {
            $why = ! $t->first_response_at && $t->response_due_at?->isPast()
                ? 'no response within ' . $t->created_at->diffForHumans($t->response_due_at, true)
                : 'not fixed within ' . $t->created_at->diffForHumans($t->resolve_due_at, true);
            $t->forceFill(['escalated_at' => now()])->save();
            ItTicketUpdate::create(['ticket_id' => $t->id, 'user_id' => null, 'note' => "Escalated: {$why}.", 'is_internal' => true]);
            Audit::record('it.ticket_escalated', "{$t->number} escalated ({$t->priority_label}): {$why} — {$t->title}", $t, flagged: true);
            $this->email($t, "Overdue IT ticket {$t->number} ({$t->priority_label}): {$t->title}",
                ucfirst($why) . '.' . "\nAssigned to: " . ($t->assignee?->name ?? 'nobody') . "\nRaised by: " . ($t->requester?->name ?? '—'));
        }

        return $tickets->count();
    }

    /**
     * Similar tickets in the last 30 days — same equipment, or same kind of
     * problem in the same place. Three or more suggests a root cause to fix.
     */
    public function similar(ItTicket $ticket)
    {
        return ItTicket::where('id', '!=', $ticket->id)->where('created_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->when($ticket->asset_id, fn ($w) => $w->orWhere('asset_id', $ticket->asset_id))
                ->orWhere(fn ($w) => $w->where('category', $ticket->category)->where(fn ($l) => $ticket->location
                    ? $l->where('location', $ticket->location) : $l->whereRaw('1 = 0'))))
            ->latest()->limit(10)->get(['id', 'number', 'title', 'status', 'created_at']);
    }

    // ------------------------------------------------------------------

    private function setDeadlines(ItTicket $ticket, Carbon $from): void
    {
        $p = $ticket->priority;
        $ticket->response_due_at = $from->copy()->addMinutes(max(1, (int) config("hotel.it.response_minutes.{$p}", 240)));
        $ticket->resolve_due_at = $from->copy()->addHours(max(1, (int) config("hotel.it.resolve_hours.{$p}", 24)));
    }

    private function note(ItTicket $ticket, ?User $by, string $note, ?string $status = null, bool $internal = false): void
    {
        ItTicketUpdate::create(['ticket_id' => $ticket->id, 'user_id' => $by?->id, 'note' => str($note)->limit(2995)->toString(), 'status' => $status, 'is_internal' => $internal]);
    }

    private function email(ItTicket $ticket, string $subject, string $body): void
    {
        $to = (array) config('hotel.it.alert_emails', []);
        if (! $to) {
            return;
        }
        try {
            Mail::raw($body . "\n\nOpen the ticket: " . route('it.tickets.show', $ticket), fn ($m) => $m->to($to)->subject('[' . config('hotel.name') . ' IT] ' . $subject));
        } catch (Throwable $e) {
            Log::warning('IT ticket email failed: ' . $e->getMessage());
        }
    }
}
