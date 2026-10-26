<?php

namespace App\Models\Attendance;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class AttendancePoint extends Model
{
    use BelongsToHotel;

    protected $fillable = ['name', 'code', 'location', 'take_photo', 'is_active'];

    protected $casts = ['take_photo' => 'boolean', 'is_active' => 'boolean'];
}
