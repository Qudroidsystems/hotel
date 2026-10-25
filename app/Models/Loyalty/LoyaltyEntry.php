<?php

namespace App\Models\Loyalty;

use App\Models\FolioCharge;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a guest's points history. Lines are never changed: a correction is a new line. */
class LoyaltyEntry extends Model
{
    public const TYPES = [
        'earn'   => ['Earned', 'st-success', 'ri-star-line'],
        'redeem' => ['Spent', 'st-info', 'ri-gift-line'],
        'adjust' => ['Adjusted', 'st-muted', 'ri-equalizer-line'],
    ];

    protected $fillable = ['guest_id', 'reservation_id', 'folio_charge_id', 'type', 'points', 'amount', 'balance_after', 'note', 'created_by'];

    protected $casts = ['amount' => 'decimal:2', 'points' => 'integer', 'balance_after' => 'integer'];

    public function guest(): BelongsTo { return $this->belongsTo(Guest::class); }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function charge(): BelongsTo { return $this->belongsTo(FolioCharge::class, 'folio_charge_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function getTypeLabelAttribute(): string { return self::TYPES[$this->type][0] ?? ucfirst($this->type); }
    public function getTypePillAttribute(): string { return self::TYPES[$this->type][1] ?? 'st-muted'; }
    public function getTypeIconAttribute(): string { return self::TYPES[$this->type][2] ?? 'ri-star-line'; }
}
