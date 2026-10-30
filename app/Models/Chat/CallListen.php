<?php

namespace App\Models\Chat;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supervisor listening in on a guest call from the call console (always audited). */
class CallListen extends Model
{
    use BelongsToHotel;

    protected $fillable = ['call_id', 'user_id', 'leg', 'status', 'reason'];

    protected $casts = ['live_at' => 'datetime', 'ended_at' => 'datetime', 'leg' => 'integer'];

    public function call(): BelongsTo { return $this->belongsTo(GuestCall::class, 'call_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isOpen(): bool { return in_array($this->status, ['waiting', 'live'], true); }
}
