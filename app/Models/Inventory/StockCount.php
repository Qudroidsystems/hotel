<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stocktake of one store. */
class StockCount extends Model
{
    protected $fillable = ['location_id', 'category_id', 'status', 'notes', 'started_by'];

    protected $casts = ['posted_at' => 'datetime', 'variance_value' => 'decimal:2'];

    public function location(): BelongsTo { return $this->belongsTo(StockLocation::class, 'location_id'); }
    public function category(): BelongsTo { return $this->belongsTo(StockCategory::class, 'category_id'); }
    public function lines(): HasMany { return $this->hasMany(StockCountLine::class); }
    public function startedBy(): BelongsTo { return $this->belongsTo(User::class, 'started_by'); }
    public function postedBy(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }

    public function isOpen(): bool { return $this->status === 'counting'; }
}
