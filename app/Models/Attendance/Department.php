<?php

namespace App\Models\Attendance;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use BelongsToHotel;

    protected $fillable = ['name', 'code', 'color', 'manager_id'];

    public function staff(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
