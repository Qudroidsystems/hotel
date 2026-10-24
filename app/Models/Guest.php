<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    public const ID_TYPES = ['National ID (NIN)', 'International passport', "Driver's licence", "Voter's card", 'Other'];

    protected $fillable = [
        'title', 'first_name', 'last_name', 'email', 'phone', 'gender', 'date_of_birth',
        'nationality', 'id_type', 'id_number', 'address', 'company', 'is_vip', 'preferences', 'notes',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'is_vip'        => 'boolean',
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class)->latest('check_in_date');
    }

    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->title ? $this->title . ' ' : '') . $this->first_name . ' ' . $this->last_name);
    }

    public function getInitialsAttribute(): string
    {
        return mb_strtoupper(mb_substr($this->first_name, 0, 1) . mb_substr($this->last_name, 0, 1));
    }

    /** Completed stays (for repeat-guest recognition). */
    public function getStayCountAttribute(): int
    {
        return $this->reservations()->where('status', ReservationStatus::CheckedOut->value)->count();
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        // MySQL concatenates with CONCAT(); SQLite/Postgres use ||.
        $fullName = in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "CONCAT(first_name, ' ', last_name)"
            : "(first_name || ' ' || last_name)";

        return $query->where(function ($q) use ($term, $fullName) {
            $like = '%' . $term . '%';
            $q->where('first_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhere('email', 'like', $like)
              ->orWhere('phone', 'like', $like)
              ->orWhere('company', 'like', $like)
              ->orWhereRaw("{$fullName} like ?", [$like]);
        });
    }
}
