<?php

namespace App\Models\Energy;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One suggestion to the control room ("switch off room 104 until 1:20 PM") and what was done about it. */
class EnergyAction extends Model
{
    use BelongsToHotel;

    public const OPEN = 'open';
    public const DONE = 'done';
    public const SKIPPED = 'skipped';
    public const EXPIRED = 'expired';

    protected $fillable = ['key', 'target', 'target_id', 'action', 'title', 'reason', 'watts', 'until', 'status', 'done_by', 'done_at', 'closed_at', 'kwh_saved', 'note', 'alerted_at', 'devices'];

    protected $casts = ['until' => 'datetime', 'done_at' => 'datetime', 'closed_at' => 'datetime', 'alerted_at' => 'datetime', 'kwh_saved' => 'float', 'watts' => 'integer', 'devices' => 'array'];

    public function doneBy()
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    /** Saving power (off / low) rather than switching something on. */
    public function saves(): bool
    {
        return in_array($this->action, ['off', 'low', 'eco'], true);
    }
}
