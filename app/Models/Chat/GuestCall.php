<?php

namespace App\Models\Chat;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A voice call between a guest and a section (WebRTC; see CallService). */
class GuestCall extends Model
{
    use BelongsToHotel;

    public const RING_SECONDS = 45;

    protected $attributes = ['leg' => 1];

    protected $fillable = ['uuid', 'conversation_id', 'direction', 'status', 'started_by'];

    protected $casts = ['answered_at' => 'datetime', 'ended_at' => 'datetime', 'rang_at' => 'datetime', 'transferred_at' => 'datetime', 'leg' => 'integer'];

    public function conversation(): BelongsTo { return $this->belongsTo(ChatConversation::class, 'conversation_id'); }
    public function answeredBy(): BelongsTo { return $this->belongsTo(User::class, 'answered_by'); }
    public function signals(): HasMany { return $this->hasMany(GuestCallSignal::class, 'call_id'); }
    public function listens(): HasMany { return $this->hasMany(CallListen::class, 'call_id'); }
    public function startedBy(): BelongsTo { return $this->belongsTo(User::class, 'started_by'); }
    public function transferToUser(): BelongsTo { return $this->belongsTo(User::class, 'transfer_to_user'); }
    public function transferToSection(): BelongsTo { return $this->belongsTo(ChatSection::class, 'transfer_to_section'); }
    public function transferredBy(): BelongsTo { return $this->belongsTo(User::class, 'transferred_by'); }

    /** When this leg started ringing (a transferred call rings again). */
    public function ringStartedAt(): \Illuminate\Support\Carbon { return $this->rang_at ?? $this->created_at; }

    public function isLive(): bool { return in_array($this->status, ['ringing', 'active'], true); }

    public function seconds(): ?int
    {
        return $this->answered_at ? (int) $this->answered_at->diffInSeconds($this->ended_at ?? now()) : null;
    }
}
