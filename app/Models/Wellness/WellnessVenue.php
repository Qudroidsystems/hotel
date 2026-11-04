<?php

namespace App\Models\Wellness;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** The spa, the gym, the pool …: hours, how many people at once, and what a session costs. */
class WellnessVenue extends Model
{
    use BelongsToHotel;

    public const KINDS = [
        'spa'   => ['Spa (treatments)', 'ri-leaf-line'],
        'gym'   => ['Gym', 'ri-run-line'],
        'pool'  => ['Pool', 'ri-water-flash-line'],
        'other' => ['Other (sessions)', 'ri-sparkling-line'],
    ];

    protected $attributes = ['kind' => 'spa', 'opens' => '07:00', 'closes' => '21:00', 'capacity' => 10, 'session_minutes' => 60, 'guest_price' => 0, 'visitors' => true, 'is_active' => true, 'sort_order' => 0];

    protected $fillable = ['name', 'kind', 'description', 'opens', 'closes', 'capacity', 'session_minutes', 'guest_price', 'visitors', 'visitor_price', 'is_active', 'sort_order'];

    protected $casts = [
        'guest_price'   => 'decimal:2',
        'visitor_price' => 'decimal:2',
        'visitors'      => 'boolean',
        'is_active'     => 'boolean',
    ];

    public function services(): HasMany { return $this->hasMany(WellnessService::class, 'venue_id')->orderBy('sort_order')->orderBy('name'); }
    public function therapists(): HasMany { return $this->hasMany(WellnessTherapist::class, 'venue_id')->orderBy('name'); }
    public function bookings(): HasMany { return $this->hasMany(WellnessBooking::class, 'venue_id'); }

    public function scopeActive($q) { return $q->where('is_active', true); }
    public function scopeOrdered($q) { return $q->orderBy('sort_order')->orderBy('name'); }

    /** Treatments are booked (the spa); otherwise a session of session_minutes for a number of people. */
    public function hasTreatments(): bool
    {
        return $this->kind === 'spa';
    }

    public function takesVisitors(): bool
    {
        return (bool) $this->visitors;
    }

    /** One session for one person. */
    public function sessionPrice(bool $visitor): float
    {
        return (float) ($visitor && $this->visitor_price !== null ? $this->visitor_price : $this->guest_price);
    }

    public function getKindLabelAttribute(): string { return self::KINDS[$this->kind][0] ?? ucfirst($this->kind); }
    public function getIconAttribute(): string { return self::KINDS[$this->kind][1] ?? 'ri-sparkling-line'; }

    /** "7:00 AM – 9:00 PM" */
    public function getHoursLabelAttribute(): string
    {
        $f = fn ($t) => Carbon::createFromFormat('H:i', $t)->format('g:i A');

        return $f($this->opens) . ' – ' . $f($this->closes);
    }
}
