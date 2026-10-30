<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in a guest chat. Never edited; staff names are shown by first name to guests. */
class ChatMessage extends Model
{
    protected $fillable = ['conversation_id', 'sender', 'user_id', 'body', 'photo', 'audio', 'audio_seconds', 'client_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime', 'audio_seconds' => 'integer'];

    public function conversation(): BelongsTo { return $this->belongsTo(ChatConversation::class, 'conversation_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo ? asset('storage/' . $this->photo) : null;
    }
}
