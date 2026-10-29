<?php

namespace App\Models\Chat;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A guest's chat with one section of the hotel, for one stay. */
class ChatConversation extends Model
{
    use BelongsToHotel;

    protected $fillable = ['section_id', 'reservation_id', 'guest_id', 'status'];

    protected $casts = [
        'last_message_at' => 'datetime', 'guest_typing_at' => 'datetime', 'staff_typing_at' => 'datetime', 'closed_at' => 'datetime',
        'guest_unread' => 'integer', 'staff_unread' => 'integer',
    ];

    public function section(): BelongsTo { return $this->belongsTo(ChatSection::class, 'section_id'); }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function guest(): BelongsTo { return $this->belongsTo(Guest::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function messages(): HasMany { return $this->hasMany(ChatMessage::class, 'conversation_id'); }
    public function calls(): HasMany { return $this->hasMany(GuestCall::class, 'conversation_id'); }

    public function guestTyping(): bool { return (bool) $this->guest_typing_at?->gt(now()->subSeconds(6)); }
    public function staffTyping(): bool { return (bool) $this->staff_typing_at?->gt(now()->subSeconds(6)); }
}
