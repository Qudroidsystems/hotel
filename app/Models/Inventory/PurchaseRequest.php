<?php

namespace App\Models\Inventory;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A department asking for stock to be bought (purchase requisition). */
class PurchaseRequest extends Model
{
    use BelongsToHotel;

    public const STATUSES = [
        'submitted' => ['Awaiting approval', 'st-warning'],
        'approved'  => ['Approved', 'st-info'],
        'ordered'   => ['Ordered', 'st-success'],
        'rejected'  => ['Rejected', 'st-danger'],
        'cancelled' => ['Cancelled', 'st-muted'],
    ];

    public const DEPARTMENTS = ['Kitchen', 'Bar', 'Restaurant', 'Housekeeping', 'Laundry', 'Maintenance', 'Front office', 'Security', 'Administration'];

    protected $fillable = ['department', 'needed_by', 'reason', 'requested_by'];

    protected $casts = [
        'needed_by'       => 'date',
        'decided_at'      => 'datetime',
        'estimated_total' => 'decimal:2',
    ];

    public function lines(): HasMany { return $this->hasMany(PurchaseRequestLine::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function decidedBy(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? ucfirst($this->status); }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
}
