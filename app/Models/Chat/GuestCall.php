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

    protected $fillable = ['uuid', 'conversation_id', 'direction', 'status', 'started_by'];

    protected $casts = ['answered_at' => 'datetime', 'ended_at' => 'datetime'];

    public function conversation(): BelongsTo { return $this->belongsTo(ChatConversation::class, 'conversation_id'); }
    public function answeredBy(): BelongsTo { return $this->belongsTo(User::class, 'answered_by'); }
    public function signals(): HasMany { return $this->hasMany(GuestCallSignal::class, 'call_id'); }

    public function isLive(): bool { return in_array($this->status, ['ringing', 'active'], true); }

    public function seconds(): ?int
    {
        return $this->answered_at ? (int) $this->answered_at->diffInSeconds($this->ended_at ?? now()) : null;
    }
}
