<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the audit trail. Call it from anywhere a sensitive action happens:
 *
 *   Audit::record('billing.charge_voided', 'Voided "Minibar" ₦4,500', $charge,
 *       old: ['voided' => false], new: ['voided' => true], reason: $reason);
 *
 * Recording never breaks the action being recorded: a failure is logged and
 * swallowed. Passwords, tokens and similar fields are never stored.
 */
class Audit
{
    /** Fields that are never written to the trail (matched as substrings). */
    private const SECRET = ['password', 'token', 'secret', 'remember', 'hash'];

    /** Fields not worth recording as changes. */
    private const NOISE = ['created_at', 'updated_at', 'last_login_at', 'status_changed_at'];

    public static function record(
        string $action,
        string $description,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        bool $flagged = false,
        ?User $by = null,
    ): ?AuditLog {
        try {
            $user = $by ?? auth()->user();
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

            return AuditLog::create([
                'user_id'       => $user?->getKey(),
                'user_name'     => $user?->name ?? (app()->runningInConsole() ? 'System' : null),
                'user_roles'    => $user ? str($user->getRoleNames()->implode(', '))->limit(250)->toString() : null,
                'module'        => str($action)->before('.')->toString(),
                'action'        => $action,
                'description'   => str($description)->limit(495)->toString(),
                'subject_type'  => $subject?->getMorphClass(),
                'subject_id'    => $subject?->getKey(),
                'subject_label' => $subject ? str(self::label($subject))->limit(145)->toString() : null,
                'old_values'    => self::clean($old) ?: null,
                'new_values'    => self::clean($new) ?: null,
                'reason'        => $reason !== null ? str($reason)->limit(495)->toString() : null,
                'flagged'       => $flagged,
                'ip_address'    => $request?->ip(),
                'user_agent'    => $request ? str((string) $request->userAgent())->limit(250)->toString() : null,
                'created_at'    => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Old and new values of the fields that changed on a model being saved. */
    public static function diff(Model $model, array $ignore = []): array
    {
        $old = $new = [];
        foreach ($model->getDirty() as $key => $value) {
            if (in_array($key, $ignore, true) || in_array($key, self::NOISE, true)) {
                continue;
            }
            $before = $model->getRawOriginal($key);
            if ((string) $before === (string) $value) {
                continue; // "1.50" vs 1.5 and the like
            }
            $old[$key] = $before;
            $new[$key] = $value;
        }

        return [$old, $new];
    }

    /** A short human name for a record: its number, reference or name. */
    public static function label(Model $model): string
    {
        foreach (['number', 'reference', 'receipt_number', 'staff_no', 'sku', 'name', 'title', 'full_name'] as $attr) {
            $value = $model->getAttribute($attr);
            if (is_scalar($value) && $value !== '') {
                $prefix = $attr === 'number' && class_basename($model) === 'Room' ? 'Room ' : '';

                return $prefix . $value;
            }
        }

        return str(class_basename($model))->headline() . ' #' . $model->getKey();
    }

    public static function isSecret(string $field): bool
    {
        return str($field)->lower()->contains(self::SECRET);
    }

    private static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (self::isSecret((string) $key)) {
                $out[$key] = '[hidden]';
                continue;
            }
            $out[$key] = match (true) {
                $value instanceof BackedEnum        => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                is_array($value)                    => self::clean($value),
                is_object($value)                   => method_exists($value, '__toString') ? (string) $value : json_encode($value),
                is_string($value)                   => str($value)->limit(500)->toString(),
                default                             => $value,
            };
        }

        return $out;
    }
}
