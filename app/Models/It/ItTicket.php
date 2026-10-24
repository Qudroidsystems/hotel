<?php

namespace App\Models\It;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An IT support request from staff (spec §11.4 service desk). */
class ItTicket extends Model
{
    public const CATEGORIES = [
        'internet' => ['Internet / network', 'ri-global-line'],
        'wifi'     => ['Wi-Fi', 'ri-wifi-line'],
        'pos'      => ['POS / till', 'ri-bank-card-line'],
        'computer' => ['Computer', 'ri-computer-line'],
        'printer'  => ['Printer', 'ri-printer-line'],
        'software' => ['Hotel system / software', 'ri-apps-2-line'],
        'account'  => ['Login / password', 'ri-key-2-line'],
        'email'    => ['Email', 'ri-mail-line'],
        'cctv'     => ['CCTV / access control', 'ri-camera-lens-line'],
        'phone'    => ['Phone / intercom', 'ri-phone-line'],
        'tv'       => ['TV / guest entertainment', 'ri-tv-2-line'],
        'other'    => ['Other', 'ri-question-line'],
    ];

    /** key => [label, pill] — shared with alert severities */
    public const PRIORITIES = [
        'critical' => ['Critical', 'st-danger'],
        'high'     => ['High', 'st-warning'],
        'normal'   => ['Normal', 'st-info'],
        'low'      => ['Low', 'st-muted'],
    ];

    public const PRIORITY_HELP = [
        'critical' => 'Hotel cannot operate — e.g. no internet, all tills down, hotel system down',
        'high'     => 'A department is badly affected — e.g. front desk PC down, bar till down',
        'normal'   => 'One person or device affected, there is a workaround',
        'low'      => 'Request or minor issue — new account, move a printer',
    ];

    public const STATUSES = [
        'open'        => ['Open', 'st-warning'],
        'in_progress' => ['In progress', 'st-info'],
        'waiting'     => ['Waiting on requester / supplier', 'st-muted'],
        'resolved'    => ['Resolved', 'st-success'],
        'closed'      => ['Closed', 'st-muted'],
    ];

    public const ACTIVE = ['open', 'in_progress', 'waiting'];

    protected $fillable = ['title', 'description', 'category', 'priority', 'department', 'location', 'asset_id', 'requested_by', 'assigned_to', 'response_due_at', 'resolve_due_at'];

    protected $casts = [
        'response_due_at'   => 'datetime',
        'resolve_due_at'    => 'datetime',
        'first_response_at' => 'datetime',
        'escalated_at'      => 'datetime',
        'resolved_at'       => 'datetime',
        'closed_at'         => 'datetime',
    ];

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
    public function asset(): BelongsTo { return $this->belongsTo(ItAsset::class, 'asset_id'); }
    public function updates(): HasMany { return $this->hasMany(ItTicketUpdate::class, 'ticket_id')->orderBy('id'); }

    public function scopeActive($q) { return $q->whereIn('status', self::ACTIVE); }

    /** Not yet answered after the response deadline, or not fixed after the resolve deadline. */
    public function scopeOverdue($q)
    {
        return $q->active()->where(fn ($w) => $w->where(fn ($r) => $r->whereNull('first_response_at')->where('response_due_at', '<', now()))
            ->orWhere('resolve_due_at', '<', now()));
    }

    public function isActive(): bool { return in_array($this->status, self::ACTIVE, true); }

    public function responseLate(): bool
    {
        return $this->response_due_at && ($this->first_response_at ? $this->first_response_at->gt($this->response_due_at) : $this->response_due_at->isPast());
    }

    public function resolveLate(): bool
    {
        return $this->resolve_due_at && ($this->resolved_at ? $this->resolved_at->gt($this->resolve_due_at) : ($this->isActive() && $this->resolve_due_at->isPast()));
    }

    public function getCategoryLabelAttribute(): string { return self::CATEGORIES[$this->category][0] ?? ucfirst($this->category); }
    public function getCategoryIconAttribute(): string { return self::CATEGORIES[$this->category][1] ?? 'ri-question-line'; }
    public function getPriorityLabelAttribute(): string { return self::PRIORITIES[$this->priority][0] ?? $this->priority; }
    public function getPriorityPillAttribute(): string { return self::PRIORITIES[$this->priority][1] ?? 'st-muted'; }
    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
}
