<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who to call in an emergency (spec §10.2). */
class EmergencyContact extends Model
{
    protected $fillable = ['name', 'role', 'phone', 'alt_phone', 'notes', 'sort_order'];
}
