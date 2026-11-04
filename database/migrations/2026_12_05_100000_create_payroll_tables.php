<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll: each person's pay (salary parts or a daily rate, deductions they have every month, bank and pension
 * details), monthly payroll runs worked out from attendance, and a payslip per person per run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_pay', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pay_type', 8)->default('monthly');            // monthly (salary), daily (paid for days worked)
            $table->decimal('basic', 14, 2)->default(0);                   // a month
            $table->decimal('housing', 14, 2)->default(0);
            $table->decimal('transport', 14, 2)->default(0);
            $table->json('allowances')->nullable();                        // [{name, amount}] other monthly allowances
            $table->decimal('daily_rate', 14, 2)->nullable();
            $table->decimal('overtime_rate', 14, 2)->nullable();           // per hour; empty = worked out from the salary
            $table->json('deductions')->nullable();                        // [{name, amount}] every month (cooperative, loan …)
            $table->boolean('pension')->default(true);
            $table->boolean('nhf')->default(false);
            $table->boolean('paye')->default(true);
            $table->decimal('annual_rent', 14, 2)->nullable();             // for rent relief
            $table->string('bank_name', 80)->nullable();
            $table->text('account_number')->nullable();                    // encrypted
            $table->string('account_name', 120)->nullable();
            $table->string('tin', 30)->nullable();
            $table->string('pension_pin', 30)->nullable();
            $table->string('pfa', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['hotel_id', 'user_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('number', 20)->nullable();
            $table->string('period', 7);                                     // 2026-11
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 10)->default('draft');                 // draft, submitted, approved, paid
            $table->unsignedInteger('staff_count')->default(0);
            $table->decimal('gross', 16, 2)->default(0);
            $table->decimal('net', 16, 2)->default(0);
            $table->decimal('paye', 16, 2)->default(0);
            $table->decimal('pension_employee', 16, 2)->default(0);
            $table->decimal('pension_employer', 16, 2)->default(0);
            $table->decimal('nhf', 16, 2)->default(0);
            $table->decimal('deductions', 16, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'period']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('staff_no', 20)->nullable();
            $table->string('name', 120);
            $table->string('department', 80)->nullable();
            $table->string('job_title', 80)->nullable();
            $table->string('pay_type', 8)->default('monthly');
            $table->json('attendance')->nullable();                          // {scheduled, present, absent, leave, unpaid_leave, late, late_minutes, overtime_minutes}
            $table->json('earnings');                                         // [{name, amount}]
            $table->json('deductions');                                       // [{name, amount}]
            $table->json('adjustments')->nullable();                          // one-off lines added for this month: [{name, amount, kind: earning|deduction}]
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('taxable', 14, 2)->default(0);
            $table->decimal('paye', 14, 2)->default(0);
            $table->decimal('pension_employee', 14, 2)->default(0);
            $table->decimal('pension_employer', 14, 2)->default(0);
            $table->decimal('nhf', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->string('bank_name', 80)->nullable();
            $table->text('account_number')->nullable();                       // encrypted
            $table->string('account_name', 120)->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'user_id']);
        });

        if (Schema::hasTable('permissions')) {
            foreach (['Manage payroll', 'Approve payroll'] as $name) {
                DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['title' => 'Payroll', 'updated_at' => now(), 'created_at' => now()]);
            }
            $perm = fn ($n) => DB::table('permissions')->where('name', $n)->where('guard_name', 'web')->value('id');
            $grants = ['HR Administrator' => ['Manage payroll'], 'Accountant' => ['Manage payroll'], 'General Manager' => ['Approve payroll'], 'Hotel Owner' => ['Approve payroll']];
            foreach ($grants as $role => $names) {
                $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
                foreach ($names as $n) {
                    if ($roleId && ($pid = $perm($n))) {
                        DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $pid, 'role_id' => $roleId]);
                    }
                }
            }
            app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('staff_pay');
        DB::table('permissions')->whereIn('name', ['Manage payroll', 'Approve payroll'])->delete();
    }
};
