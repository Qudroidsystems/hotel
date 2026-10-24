<?php

namespace App\Models\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class StaffLeave extends Model
{
    public const TYPES = ['annual' => 'Annual leave', 'sick' => 'Sick leave', 'maternity' => 'Maternity / paternity', 'compassionate' => 'Compassionate', 'training' => 'Training / course', 'unpaid' => 'Unpaid leave', 'other' => 'Other'];

    protected $fillable = ['user_id', 'type', 'from_date', 'to_date', 'reason', 'status', 'created_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date'];

    public function setFromDateAttribute($v): void { $this->attributes['from_date'] = Carbon::parse($v)->toDateString(); }
    public function setToDateAttribute($v): void { $this->attributes['to_date'] = Carbon::parse($v)->toDateString(); }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }

    public function scopeCovering($q, $date)
    {
        $d = Carbon::parse($date)->toDateString();

        return $q->where('from_date', '<=', $d)->where('to_date', '>=', $d);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function getDaysAttribute(): int
    {
        return (int) $this->from_date->diffInDays($this->to_date) + 1;
    }
}
