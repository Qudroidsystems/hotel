<?php

namespace App\Models\Energy;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A power change (grid off, a generator started or stopped) or a fuel reading. */
class EnergyEvent extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['source_id', 'kind', 'value', 'note', 'user_id', 'by'];

    protected $casts = ['value' => 'float'];

    public function source()
    {
        return $this->belongsTo(EnergySource::class, 'source_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
