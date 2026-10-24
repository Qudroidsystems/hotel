<?php

namespace App\Providers;

use App\Enums\ChargeType;
use App\Enums\PaymentKind;
use App\Enums\StockMovementType;
use App\Models\Attendance\AttendancePoint;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\Department;
use App\Models\Attendance\Shift;
use App\Models\Attendance\StaffLeave;
use App\Models\Attendance\StaffProfile;
use App\Models\Attendance\StaffRota;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\EmergencyContact;
use App\Models\Floor;
use App\Models\FolioCharge;
use App\Models\Guest;
use App\Models\HotelBankAccount;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\StockCategory;
use App\Models\Inventory\StockCount;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Supplier;
use App\Models\Payment;
use App\Models\Pos\PosCategory;
use App\Models\Pos\PosItem;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosOutlet;
use App\Models\ReservationEvent;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Feeds the audit trail (spec §14) from model and sign-in events, so every
 * screen, service and the offline till sync is covered without each one
 * having to remember. Role/permission assignments (pivot tables, no model
 * events) are recorded in RoleController and UserController.
 */
class AuditServiceProvider extends ServiceProvider
{
    /**
     * Set-up records: every create, change and delete is recorded with old and
     * new values. model => [module, fields not worth recording]
     */
    private const WATCHED = [
        Room::class             => ['rooms', ['status']],         // status changes come from RoomStatusLog
        RoomType::class         => ['rooms', []],
        Building::class         => ['rooms', []],
        Floor::class            => ['rooms', []],
        Guest::class            => ['guests', []],
        PosItem::class          => ['pos', ['sort_order']],
        PosCategory::class      => ['pos', ['sort_order']],
        PosOutlet::class        => ['pos', []],
        StockItem::class        => ['inventory', []],
        StockLocation::class    => ['inventory', []],
        StockCategory::class    => ['inventory', []],
        Supplier::class         => ['inventory', []],
        HotelBankAccount::class => ['settings', ['sort_order']],
        EmergencyContact::class => ['security', ['sort_order']],
        Shift::class            => ['attendance', []],
        Department::class       => ['attendance', []],
        AttendancePoint::class  => ['attendance', ['last_seen_at']],
        StaffProfile::class     => ['attendance', ['badge_issued_at']],
        User::class             => ['users', ['avatar', 'must_change_password']],
        Role::class             => ['roles', []],
        Permission::class       => ['roles', []],
    ];

    /** Reservation timeline events copied into the trail (charges and payments are recorded from billing). */
    private const RESERVATION_EVENTS = ['checked_in', 'checked_out', 'cancelled', 'no_show', 'dates_changed', 'discount', 'qr_scanned'];

    public function boot(): void
    {
        foreach (self::WATCHED as $class => [$module, $ignore]) {
            $this->watch($class, $module, $ignore);
        }

        $this->billing();
        $this->pos();
        $this->inventory();
        $this->attendance();
        $this->frontDesk();
        $this->signIns();
    }

    private function watch(string $class, string $module, array $ignore): void
    {
        $name = fn (Model $m) => str(class_basename($m))->headline()->lower()->toString();

        $class::created(function (Model $m) use ($module, $name, $ignore) {
            $values = collect($m->getAttributes())->except(array_merge($ignore, ['id', 'created_at', 'updated_at']))->all();
            Audit::record("{$module}.created", 'Added ' . $name($m) . ' ' . Audit::label($m), $m, new: $values);
        });

        $class::updated(function (Model $m) use ($module, $name, $ignore) {
            [$old, $new] = Audit::diff($m, $ignore);
            if (! $old && ! $new) {
                return;
            }
            $what = array_keys($new);
            $password = in_array('password', $what, true);
            $desc = $password && count($what) === 1
                ? 'Changed the password of ' . Audit::label($m)
                : 'Changed ' . $name($m) . ' ' . Audit::label($m) . ': ' . implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $what));
            Audit::record("{$module}.updated", $desc, $m, $old, $new);
        });

        $class::deleted(function (Model $m) use ($module, $name, $ignore) {
            $values = collect($m->getAttributes())->except(array_merge($ignore, ['created_at', 'updated_at']))->all();
            Audit::record("{$module}.deleted", 'Deleted ' . $name($m) . ' ' . Audit::label($m), $m, old: $values, flagged: $m instanceof User);
        });
    }

    private function billing(): void
    {
        FolioCharge::created(function (FolioCharge $c) {
            if (! in_array($c->type, [ChargeType::Discount, ChargeType::Adjustment], true)) {
                return;
            }
            $folio = $c->folio;
            Audit::record(
                $c->type === ChargeType::Discount ? 'billing.discount' : 'billing.adjustment',
                "{$c->type->label()} of " . $this->money($c->total) . " on {$folio?->number}: {$c->description}",
                $folio,
                new: ['description' => $c->description, 'amount' => $c->total],
                flagged: abs((float) $c->total) >= $this->flagAmount(),
            );
        });

        FolioCharge::updated(function (FolioCharge $c) {
            if (! $c->wasChanged('voided_at') || ! $c->voided_at) {
                return;
            }
            Audit::record('billing.charge_voided', "Voided charge \"{$c->description}\" (" . $this->money($c->total) . ") on {$c->folio?->number}",
                $c->folio, ['description' => $c->description, 'amount' => $c->total, 'voided' => false], ['voided' => true],
                $c->void_reason, abs((float) $c->total) >= $this->flagAmount());
        });

        Payment::created(function (Payment $p) {
            if ($p->kind !== PaymentKind::Refund) {
                return;
            }
            Audit::record('billing.refund', 'Refunded ' . $this->money($p->amount) . " by {$p->method->label()} on {$p->folio?->number} (receipt {$p->receipt_number})",
                $p->folio, new: ['amount' => $p->amount, 'method' => $p->method, 'reference' => $p->reference],
                reason: $p->notes, flagged: (float) $p->amount >= $this->flagAmount());
        });

        Payment::updated(function (Payment $p) {
            if (! $p->wasChanged('voided_at') || ! $p->voided_at) {
                return;
            }
            Audit::record('billing.payment_voided', "Voided {$p->kind->label()} {$p->receipt_number} of " . $this->money($p->amount) . " on {$p->folio?->number}",
                $p->folio, ['receipt' => $p->receipt_number, 'amount' => $p->amount, 'method' => $p->method, 'voided' => false], ['voided' => true],
                $p->void_reason, (float) $p->amount >= $this->flagAmount());
        });
    }

    private function pos(): void
    {
        PosOrder::created(function (PosOrder $o) {
            if ((float) $o->discount_amount <= 0) {
                return;
            }
            Audit::record('pos.discount', 'Discount of ' . $this->money($o->discount_amount) . " on sale {$o->number} (" . $this->money($o->total) . ' after discount)',
                $o, new: ['subtotal' => $o->subtotal, 'discount' => $o->discount_amount, 'total' => $o->total],
                reason: $o->discount_reason, flagged: (float) $o->discount_amount >= $this->flagAmount(), by: $o->cashier);
        });

        PosOrder::updated(function (PosOrder $o) {
            if ($o->wasChanged('status') && $o->status === PosOrder::VOIDED) {
                Audit::record('pos.voided', "Voided sale {$o->number} of " . $this->money($o->total),
                    $o, ['status' => $o->getOriginal('status'), 'total' => $o->total], ['status' => PosOrder::VOIDED],
                    $o->void_reason, (float) $o->total >= $this->flagAmount());
            } elseif ($o->wasChanged('resolved_at') && $o->resolved_at) {
                Audit::record('pos.resolved', "Settled flagged sale {$o->number} (" . $this->money($o->total) . ')', $o,
                    ['status' => $o->getOriginal('status')], ['status' => $o->status, 'method' => $o->method]);
            }
        });
    }

    private function inventory(): void
    {
        StockMovement::created(function (StockMovement $m) {
            $manual = [StockMovementType::Adjustment, StockMovementType::Waste, StockMovementType::Issue, StockMovementType::Count, StockMovementType::Opening];
            if (! in_array($m->type, $manual, true)) {
                return;
            }
            $item = $m->item;
            $qty = rtrim(rtrim(number_format((float) $m->quantity, 3, '.', ''), '0'), '.');
            Audit::record('inventory.' . $m->type->value,
                "{$m->type->label()}: " . ((float) $m->quantity > 0 ? '+' : '') . "{$qty} {$item?->unit} {$item?->name} at {$m->location?->name}",
                $item, new: ['quantity' => $m->quantity, 'value' => $m->value, 'balance_after' => $m->balance_after, 'location' => $m->location?->name],
                reason: $m->note, flagged: $m->type !== StockMovementType::Opening && abs((float) $m->value) >= $this->flagAmount());
        });

        PurchaseOrder::updated(function (PurchaseOrder $po) {
            if ($po->wasChanged('status') && in_array($po->status, ['ordered', 'cancelled'], true)) {
                Audit::record("inventory.po_{$po->status}", ($po->status === 'ordered' ? 'Sent' : 'Cancelled') . " purchase order {$po->number} (" . $this->money($po->total) . ')',
                    $po, ['status' => $po->getOriginal('status')], ['status' => $po->status]);
            }
        });

        StockCount::updated(function (StockCount $c) {
            if ($c->wasChanged('status') && in_array($c->status, ['posted', 'cancelled'], true)) {
                Audit::record("inventory.count_{$c->status}", ucfirst($c->status) . " stocktake {$c->number}", $c,
                    ['status' => $c->getOriginal('status')], ['status' => $c->status]);
            }
        });
    }

    private function attendance(): void
    {
        AttendancePunch::created(function (AttendancePunch $p) {
            if ($p->source !== 'manual') {
                return;
            }
            Audit::record('attendance.punch_added', "Added a clock-{$p->direction} for {$p->user?->name} at " . $p->punched_at->format('D j M, g:i A'),
                $p, new: ['punched_at' => $p->punched_at, 'direction' => $p->direction], reason: $p->note);
        });

        AttendancePunch::updated(function (AttendancePunch $p) {
            if (! $p->wasChanged('voided_at') || ! $p->voided_at) {
                return;
            }
            Audit::record('attendance.punch_removed', "Removed a clock-{$p->direction} for {$p->user?->name} at " . $p->punched_at->format('D j M, g:i A'),
                $p, ['punched_at' => $p->punched_at, 'source' => $p->source], ['voided' => true], $p->void_reason);
        });

        StaffRota::saved(function (StaffRota $r) {
            Audit::record('attendance.rota_changed', "Changed the shift of {$r->user?->name} on " . $r->work_date?->format('D j M Y'), $r,
                ['shift_id' => $r->getOriginal('shift_id')], ['shift_id' => $r->shift_id], $r->note);
        });

        StaffLeave::created(fn (StaffLeave $l) => Audit::record('attendance.leave_added',
            "Recorded {$l->type} leave for {$l->user?->name}, " . $l->from_date?->format('j M') . ' – ' . $l->to_date?->format('j M Y'), $l,
            new: ['type' => $l->type, 'from' => $l->from_date, 'to' => $l->to_date], reason: $l->reason));

        StaffLeave::updated(function (StaffLeave $l) {
            if ($l->wasChanged('status')) {
                Audit::record('attendance.leave_' . $l->status, "Leave of {$l->user?->name} " . str_replace('_', ' ', $l->status), $l,
                    ['status' => $l->getOriginal('status')], ['status' => $l->status]);
            }
        });
    }

    private function frontDesk(): void
    {
        ReservationEvent::created(function (ReservationEvent $e) {
            if (! in_array($e->event, self::RESERVATION_EVENTS, true)) {
                return;
            }
            $reservation = \App\Models\Reservation::find($e->reservation_id);
            $flag = $e->event === 'checked_out' && str_contains($e->description, 'Left owing')
                || $e->event === 'qr_scanned' && str_contains($e->description, 'not valid');
            Audit::record("reservations.{$e->event}", ($reservation ? "{$reservation->reference}: " : '') . $e->description, $reservation, flagged: $flag);
        });

        RoomStatusLog::created(function (RoomStatusLog $log) {
            if (! $log->user_id) {
                return; // automatic (check-out, arrivals) — already on the room's own history
            }
            Audit::record('rooms.status_changed', "Room {$log->room?->number}: {$log->from_status?->label()} → {$log->to_status?->label()}",
                $log->room, ['status' => $log->from_status], ['status' => $log->to_status], $log->reason);
        });
    }

    private function signIns(): void
    {
        Event::listen(Login::class, fn (Login $e) => Audit::record('auth.login', "{$e->user->name} signed in", $e->user, by: $e->user));
        Event::listen(Logout::class, fn (Logout $e) => $e->user ? Audit::record('auth.logout', "{$e->user->name} signed out", $e->user, by: $e->user) : null);
        Event::listen(PasswordReset::class, fn (PasswordReset $e) => Audit::record('auth.password_reset', "{$e->user->name} reset their password by email", $e->user, by: $e->user));
        Event::listen(Lockout::class, fn (Lockout $e) => Audit::record('auth.lockout',
            'Sign-in locked for a while after too many attempts for "' . str((string) $e->request->input('email'))->limit(100) . '"', flagged: true));

        Event::listen(Failed::class, function (Failed $e) {
            $login = str((string) ($e->credentials['email'] ?? $e->credentials['username'] ?? ''))->limit(100)->toString();
            $recent = AuditLog::where('action', 'auth.failed')->where('created_at', '>=', now()->subMinutes(15))
                ->where(fn ($q) => $q->where('ip_address', request()->ip())->orWhere('new_values->login', $login))->count();
            Audit::record('auth.failed', "Failed sign-in for \"{$login}\"" . ($e->user ? '' : ' (no such account)'), $e->user,
                new: ['login' => $login], flagged: $recent + 1 >= config('hotel.audit.flag_failures', 5), by: null);
        });
    }

    private function money(float|string|null $amount): string
    {
        return config('hotel.currency_symbol') . number_format(abs((float) $amount), 2);
    }

    private function flagAmount(): float
    {
        return (float) config('hotel.audit.flag_amount', 50000);
    }
}
