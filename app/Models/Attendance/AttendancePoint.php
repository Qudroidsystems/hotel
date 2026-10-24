<?php

namespace App\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

class AttendancePoint extends Model
{
    protected $fillable = ['name', 'code', 'location', 'take_photo', 'is_active'];

    protected $casts = ['take_photo' => 'boolean', 'is_active' => 'boolean'];
}
