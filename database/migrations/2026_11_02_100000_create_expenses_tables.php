<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operating expenses (spec §9 expense recording and approvals, §14): what the
 * hotel spends to run, apart from stock bought through Inventory. Categories
 * carry an optional monthly budget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('group', 20)->default('operations');       // staff, utilities, operations, maintenance, sales, admin, finance, other
            $table->decimal('monthly_budget', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();         // EXP-000123
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('department', 80)->nullable()->index();
            $table->string('description', 255);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee', 150)->nullable();                   // when not a stock supplier
            $table->decimal('amount', 14, 2);                           // what is paid, incl. any VAT
            $table->decimal('tax_amount', 14, 2)->default(0);           // VAT included in amount, if any
            $table->date('expense_date')->index();                      // when the cost belongs (for profit & loss)
            $table->string('invoice_ref', 60)->nullable();              // the payee's invoice / receipt number
            $table->string('notes', 1000)->nullable();
            $table->json('attachments')->nullable();                    // receipt photos / PDFs (private disk)
            $table->string('status', 12)->default('pending')->index();  // pending, approved, rejected, paid, void
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 20)->nullable();                   // cash, transfer, card, cheque, other
            $table->foreignId('bank_account_id')->nullable()->constrained('hotel_bank_accounts')->nullOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();   // cash taken from a till
            $table->string('payment_ref', 100)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['category_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
