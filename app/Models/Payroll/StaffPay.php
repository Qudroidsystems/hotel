<?php

namespace App\Models\Payroll;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's pay: salary parts (or a daily rate), monthly deductions, bank, tax and pension details. */
class StaffPay extends Model
{
    use BelongsToHotel;

    protected $table = 'staff_pay';

    protected $attributes = ['pay_type' => 'monthly', 'basic' => 0, 'housing' => 0, 'transport' => 0, 'pension' => true, 'nhf' => false, 'paye' => true, 'is_active' => true];

    protected $fillable = ['user_id', 'pay_type', 'basic', 'housing', 'transport', 'allowances', 'daily_rate', 'overtime_rate', 'deductions', 'pension', 'nhf', 'paye',
        'annual_rent', 'bank_name', 'account_number', 'account_name', 'tin', 'pension_pin', 'pfa', 'is_active'];

    protected $casts = [
        'basic'          => 'decimal:2',
        'housing'        => 'decimal:2',
        'transport'      => 'decimal:2',
        'daily_rate'     => 'decimal:2',
        'overtime_rate'  => 'decimal:2',
        'annual_rent'    => 'decimal:2',
        'allowances'     => 'array',
        'deductions'     => 'array',
        'pension'        => 'boolean',
        'nhf'            => 'boolean',
        'paye'           => 'boolean',
        'is_active'      => 'boolean',
        'account_number' => 'encrypted',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** A month's pay before anything is taken off (monthly staff; daily staff depend on days worked). */
    public function getMonthlyGrossAttribute(): float
    {
        return (float) $this->basic + (float) $this->housing + (float) $this->transport + (float) collect($this->allowances ?? [])->sum('amount');
    }

    /** "•••• 4321" */
    public function getAccountMaskedAttribute(): ?string
    {
        return $this->account_number ? '•••• ' . substr($this->account_number, -4) : null;
    }
}
