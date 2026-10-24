<?php

namespace App\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class StaffRota extends Model
{
    protected $table = 'staff_rota';

    protected $fillable = ['user_id', 'work_date', 'shift_id', 'note', 'created_by'];

    protected $casts = ['work_date' => 'date'];

    public function setWorkDateAttribute($value): void
    {
        $this->attributes['work_date'] = Carbon::parse($value)->toDateString();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
