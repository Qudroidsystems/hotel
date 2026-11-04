<?php

namespace App\Models\Payroll;

use App\Models\Finance\Expense;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A month's payroll: draft → submitted → approved → paid. */
class PayrollRun extends Model
{
    use BelongsToHotel;

    public const STATUSES = [
        'draft'     => ['Draft', 'st-muted'],
        'submitted' => ['Waiting for approval', 'st-warning'],
        'approved'  => ['Approved — to pay', 'st-info'],
        'paid'      => ['Paid', 'st-success'],
    ];

    protected $fillable = ['period', 'from_date', 'to_date', 'status', 'notes', 'created_by'];

    protected $casts = [
        'from_date'        => 'date',
        'to_date'          => 'date',
        'submitted_at'     => 'datetime',
        'approved_at'      => 'datetime',
        'paid_at'          => 'datetime',
        'gross'            => 'decimal:2',
        'net'              => 'decimal:2',
        'paye'             => 'decimal:2',
        'pension_employee' => 'decimal:2',
        'pension_employer' => 'decimal:2',
        'nhf'              => 'decimal:2',
        'deductions'       => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::created(fn (PayrollRun $r) => $r->forceFill(['number' => 'PR-' . str_replace('-', '', $r->period) . '-' . $r->id])->saveQuietly());
    }

    public function payslips(): HasMany { return $this->hasMany(Payslip::class, 'run_id')->orderBy('name'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function payer(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }
    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }

    public function getMonthLabelAttribute(): string
    {
        return Carbon::createFromFormat('!Y-m', $this->period)->format('F Y');
    }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
