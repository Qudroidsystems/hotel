<?php

namespace App\Models\Booking;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request sent by a guest from their booking page (spec §12.2). */
class GuestRequest extends Model
{
    use BelongsToHotel;

    /** type => [label, icon, where it goes: housekeeping | maintenance | front_desk] */
    public const TYPES = [
        'towels'       => ['Fresh towels', 'ri-t-shirt-air-line', 'housekeeping'],
        'cleaning'     => ['Clean my room', 'ri-brush-line', 'housekeeping'],
        'amenities'    => ['Toiletries / amenities', 'ri-hand-sanitizer-line', 'housekeeping'],
        'repair'       => ['Something is broken', 'ri-tools-line', 'maintenance'],
        'room_service' => ['Food & drinks to my room', 'ri-restaurant-line', 'front_desk'],
        'wake_up'      => ['Wake-up call', 'ri-alarm-line', 'front_desk'],
        'transport'    => ['Taxi / airport pickup', 'ri-taxi-line', 'front_desk'],
        'other'        => ['Something else', 'ri-question-answer-line', 'front_desk'],
    ];

    public const STATUSES = [
        'open'        => ['Received', 'st-warning'],
        'in_progress' => ['On it', 'st-info'],
        'done'        => ['Done', 'st-success'],
        'cancelled'   => ['Cancelled', 'st-muted'],
    ];

    protected $fillable = ['reservation_id', 'room_id', 'type', 'details', 'status'];

    protected $casts = ['handled_at' => 'datetime'];

    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function housekeepingTask(): BelongsTo { return $this->belongsTo(HousekeepingTask::class); }
    public function maintenanceOrder(): BelongsTo { return $this->belongsTo(MaintenanceOrder::class); }
    public function handler(): BelongsTo { return $this->belongsTo(User::class, 'handled_by'); }

    public function scopeActive($q) { return $q->whereIn('status', ['open', 'in_progress']); }

    public function getTypeLabelAttribute(): string { return self::TYPES[$this->type][0] ?? ucfirst($this->type); }
    public function getTypeIconAttribute(): string { return self::TYPES[$this->type][1] ?? 'ri-question-line'; }
    public function getRouteAttribute(): string { return self::TYPES[$this->type][2] ?? 'front_desk'; }
    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
}
