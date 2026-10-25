<?php

namespace App\Services\Mobile;

use App\Models\Booking\GuestRequest;
use App\Models\CashShift;
use App\Models\Finance\Expense;
use App\Models\HousekeepingTask;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\PurchaseRequest;
use App\Models\It\ItAlert;
use App\Models\It\ItTicket;
use App\Models\MaintenanceOrder;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about what, in the staff app's Alerts and as push notifications.
 * Hooked to the records themselves, so it works whichever screen (website,
 * staff app, phone app, scheduler) made the change.
 */
class StaffAlerts
{
    public static function register(): void
    {
        $self = new self();

        GuestRequest::created(fn (GuestRequest $g) => $self->guestRequest($g));
        HousekeepingTask::saved(fn (HousekeepingTask $t) => $t->wasChanged('assigned_to') || ($t->wasRecentlyCreated && $t->assigned_to) ? $self->roomAssigned($t) : null);
        MaintenanceOrder::saved(fn (MaintenanceOrder $o) => $self->repair($o));
        ItTicket::saved(fn (ItTicket $t) => $t->wasChanged('assigned_to') || ($t->wasRecentlyCreated && $t->assigned_to) ? $self->ticketAssigned($t) : null);
        ItAlert::created(fn (ItAlert $a) => $self->itAlert($a));
        Expense::saved(fn (Expense $e) => $self->becomes($e, 'pending') ? $self->expense($e) : null);
        PurchaseOrder::saved(fn (PurchaseOrder $po) => $self->becomes($po, 'pending') ? $self->purchaseOrder($po) : null);
        PurchaseRequest::saved(fn (PurchaseRequest $pr) => $self->becomes($pr, 'submitted') ? $self->purchaseRequest($pr) : null);
        CashShift::saved(fn (CashShift $s) => $self->becomes($s, CashShift::CLOSED) ? $self->cashShift($s) : null);
    }

    private function guestRequest(GuestRequest $g): void
    {
        $g = $g->fresh(['room', 'reservation.guest']) ?? $g;   // a copy: never change the record the caller is still using
        $vip = (bool) $g->reservation?->guest?->is_vip;
        $this->send($this->staffWith('Handle guest requests'), [
            'title' => 'Room ' . ($g->room?->number ?? '—') . ': ' . $g->type_label . ($vip ? ' · VIP' : ''),
            'body'  => $g->details ?: ($g->reservation?->guest?->full_name ?? 'A guest') . ' asked from their booking page.',
            'group' => 'guests', 'level' => $vip ? 'urgent' : 'normal', 'url' => 'work', 'thread' => "guest:{$g->id}", 'kind' => 'guest', 'item_id' => $g->id,
        ]);
    }

    private function roomAssigned(HousekeepingTask $t): void
    {
        if (! $t->assigned_to || $t->assigned_to === auth()->id()) {
            return;
        }
        $t = $t->fresh('room') ?? $t;
        $this->send(User::whereKey($t->assigned_to)->get(), [
            'title' => 'Room ' . $t->room?->number . ' to clean' . ($t->urgent ? ' — urgent' : ''),
            'body'  => $t->type_label . ($t->notes ? ': ' . $t->notes : ''),
            'group' => 'work', 'level' => $t->urgent ? 'urgent' : 'normal', 'url' => 'work', 'thread' => "housekeeping:{$t->id}", 'kind' => 'housekeeping', 'item_id' => $t->id,
        ]);
    }

    private function repair(MaintenanceOrder $o): void
    {
        if (! $o->number) {
            return;   // the work-order number is filled in by a second save; alert then
        }
        $c = $o->fresh('room') ?? $o;   // a copy for the wording; $o keeps what just changed
        $data = [
            'body'  => $c->title . ' · ' . $c->where,
            'group' => 'work', 'level' => $o->priority === 'urgent' ? 'urgent' : 'normal', 'url' => 'work', 'thread' => "maintenance:{$o->id}", 'kind' => 'maintenance', 'item_id' => $o->id,
        ];
        if ($o->assigned_to && ($o->wasChanged('assigned_to') || $o->wasRecentlyCreated) && $o->assigned_to !== auth()->id()) {
            $this->send(User::whereKey($o->assigned_to)->get(), ['title' => "{$o->number} assigned to you"] + $data);
        } elseif ($o->wasRecentlyCreated && ! $o->assigned_to && in_array($o->priority, ['urgent', 'high'], true)) {
            $this->send($this->staffWith('Manage maintenance'), ['title' => ucfirst($o->priority) . " repair reported: {$o->number}"] + $data);
        }
    }

    private function ticketAssigned(ItTicket $t): void
    {
        if ($t->assigned_to === auth()->id()) {
            return;
        }
        $this->send(User::whereKey($t->assigned_to)->get(), [
            'title' => "{$t->number} assigned to you", 'body' => $t->title,
            'group' => 'it', 'level' => in_array($t->priority, ['critical', 'high'], true) ? 'urgent' : 'normal', 'url' => route('it.tickets.show', $t), 'thread' => "ticket:{$t->id}",
        ]);
    }

    private function itAlert(ItAlert $a): void
    {
        if (! in_array($a->severity, ['critical', 'high'], true)) {
            return;
        }
        $this->send($this->staffWith('Manage IT monitoring'), [
            'title' => $a->title, 'body' => (string) ($a->message ?: 'Open the IT Control Room for details.'),
            'group' => 'it', 'level' => 'urgent', 'url' => route('it.alerts.index'), 'thread' => "it-alert:{$a->monitor_id}",
        ]);
    }

    private function expense(Expense $e): void
    {
        $this->send($this->staffWith('Approve expenses', [$e->recorded_by]), [
            'title' => "Expense to approve: {$e->number}",
            'body'  => $this->money($e->amount) . ' · ' . $e->description,
            'group' => 'approvals', 'url' => 'approvals', 'thread' => "expense:{$e->id}", 'kind' => 'expense', 'item_id' => $e->id,
        ]);
    }

    private function purchaseOrder(PurchaseOrder $po): void
    {
        $po = $po->fresh('supplier') ?? $po;
        $this->send($this->staffWith('Approve purchases', [$po->created_by, $po->submitted_by]), [
            'title' => "Purchase order to approve: {$po->number}",
            'body'  => $this->money($po->total) . ' · ' . ($po->supplier?->name ?? 'Supplier'),
            'group' => 'approvals', 'url' => 'approvals', 'thread' => "po:{$po->id}", 'kind' => 'po', 'item_id' => $po->id,
        ]);
    }

    private function purchaseRequest(PurchaseRequest $pr): void
    {
        $this->send($this->staffWith('Approve purchases', [$pr->requested_by]), [
            'title' => "Purchase request: {$pr->number}",
            'body'  => trim($pr->department . ($pr->reason ? ' · ' . $pr->reason : '')),
            'group' => 'approvals', 'url' => 'approvals', 'thread' => "pr:{$pr->id}", 'kind' => 'pr', 'item_id' => $pr->id,
        ]);
    }

    private function cashShift(CashShift $s): void
    {
        $s = $s->fresh('user') ?? $s;
        $v = (float) $s->variance;
        $this->send($this->staffWith('Review cash shifts', [$s->user_id]), [
            'title' => "Shift to sign off: {$s->number}",
            'body'  => ($s->user?->name ?? 'Cashier') . ' · ' . $s->station . ' · ' . (abs($v) < 0.005 ? 'cash matched' : ($v < 0 ? 'short ' : 'over ') . $this->money(abs($v))),
            'group' => 'approvals', 'level' => abs($v) >= 0.005 ? 'urgent' : 'normal', 'url' => 'approvals', 'thread' => "shift:{$s->id}", 'kind' => 'shift', 'item_id' => $s->id,
        ]);
    }

    // ------------------------------------------------------------------

    private function becomes($model, string $status): bool
    {
        return $model->status === $status && ($model->wasRecentlyCreated || $model->wasChanged('status'));
    }

    /** Active staff who hold a permission (directly or through a role), never the person who did it. */
    private function staffWith(string $permission, array $except = []): Collection
    {
        try {
            $except = array_filter([...$except, auth()->id()]);

            return User::permission($permission)->where('is_disabled', false)
                ->when($except, fn ($q) => $q->whereNotIn('users.id', $except))->get();
        } catch (\Throwable) {
            return collect();   // permission not set up on this install yet
        }
    }

    private function send(Collection $users, array $data): void
    {
        static $ready = null;
        $ready ??= \Illuminate\Support\Facades\Schema::hasTable('notifications');
        if (! $ready || $users->isEmpty()) {
            return;   // before `php artisan migrate`, or nobody to tell
        }
        // A record saved twice in one go (number filled in afterwards ...) alerts once.
        static $sent = [];
        $once = ($data['thread'] ?? '') . '|' . $data['title'] . '|' . $users->pluck('id')->implode(',');
        if (isset($sent[$once])) {
            return;
        }
        $sent[$once] = true;
        try {
            Notification::send($users, new StaffAlert($data));
        } catch (\Throwable $e) {
            // An alert must never stop the work that raised it.
            Log::warning('Staff alert not sent: ' . $e->getMessage());
        }
    }

    private function money($v): string
    {
        return config('hotel.currency_symbol') . number_format((float) $v, 2);
    }
}
