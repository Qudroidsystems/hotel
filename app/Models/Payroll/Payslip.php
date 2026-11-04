<?php

namespace App\Models\Payroll;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's pay for one payroll run. */
class Payslip extends Model
{
    use BelongsToHotel;

    protected $fillable = ['run_id', 'user_id', 'staff_no', 'name', 'department', 'job_title', 'pay_type', 'attendance', 'earnings', 'deductions', 'adjustments',
        'gross', 'taxable', 'paye', 'pension_employee', 'pension_employer', 'nhf', 'total_deductions', 'net', 'bank_name', 'account_number', 'account_name'];

    protected $casts = [
        'attendance'       => 'array',
        'earnings'         => 'array',
        'deductions'       => 'array',
        'adjustments'      => 'array',
        'gross'            => 'decimal:2',
        'taxable'          => 'decimal:2',
        'paye'             => 'decimal:2',
        'pension_employee' => 'decimal:2',
        'pension_employer' => 'decimal:2',
        'nhf'              => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net'              => 'decimal:2',
        'account_number'   => 'encrypted',
        'emailed_at'       => 'datetime',
    ];

    public function run(): BelongsTo { return $this->belongsTo(PayrollRun::class, 'run_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
