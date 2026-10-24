<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $fillable = ['from_location_id', 'to_location_id', 'notes', 'idempotency_key', 'created_by'];

    public function from(): BelongsTo { return $this->belongsTo(StockLocation::class, 'from_location_id'); }
    public function to(): BelongsTo { return $this->belongsTo(StockLocation::class, 'to_location_id'); }
    public function lines(): HasMany { return $this->hasMany(StockTransferLine::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
