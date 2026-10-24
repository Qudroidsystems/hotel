<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One entry in the audit trail. Append-only: written through App\Support\Audit,
 * never updated or deleted from the application.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** module => [label, icon] for filters and badges. */
    public const MODULES = [
        'auth'         => ['Sign-in',             'ri-login-circle-line'],
        'users'        => ['Users',               'ri-user-settings-line'],
        'roles'        => ['Roles & permissions', 'ri-shield-keyhole-line'],
        'reservations' => ['Front desk',          'ri-hotel-bed-line'],
        'guests'       => ['Guests',              'ri-user-star-line'],
        'rooms'        => ['Rooms',               'ri-door-line'],
        'billing'      => ['Billing',             'ri-bill-line'],
        'pos'          => ['Bar & restaurant',    'ri-goblet-line'],
        'inventory'    => ['Inventory',           'ri-archive-line'],
        'attendance'   => ['Attendance',          'ri-fingerprint-line'],
        'settings'     => ['Settings',            'ri-settings-3-line'],
        'security'     => ['Security',            'ri-alarm-warning-line'],
    ];

    protected $fillable = [
        'user_id', 'user_name', 'user_roles', 'module', 'action', 'description', 'subject_type', 'subject_id',
        'subject_label', 'old_values', 'new_values', 'reason', 'flagged', 'ip_address', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'flagged'    => 'boolean',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        return $q->where(fn ($w) => $w->where('description', 'like', $like)
            ->orWhere('subject_label', 'like', $like)
            ->orWhere('reason', 'like', $like)
            ->orWhere('user_name', 'like', $like)
            ->orWhere('ip_address', 'like', $like));
    }

    public function moduleLabel(): string
    {
        return self::MODULES[$this->module][0] ?? ucfirst($this->module);
    }

    public function icon(): string
    {
        return self::MODULES[$this->module][1] ?? 'ri-file-list-3-line';
    }

    /** The action without its module prefix, made readable: "charge voided". */
    public function actionLabel(): string
    {
        return str_replace('_', ' ', str($this->action)->after('.')->toString());
    }

    /** Field-by-field changes: [field => [old, new]] (either side may be absent). */
    public function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $rows = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $rows[$key] = [$old[$key] ?? null, $new[$key] ?? null];
        }

        return $rows;
    }
}
