<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffTaskUpdate extends Model
{
    use BelongsToHotel;

    protected $fillable = ['staff_task_id', 'user_id', 'kind', 'body', 'attachment'];

    public function task(): BelongsTo { return $this->belongsTo(StaffTask::class, 'staff_task_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
