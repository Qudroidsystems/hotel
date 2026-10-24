<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement (spec §8.3): purchase requests from departments, approval of
 * purchase orders above a spending limit, goods rejected at delivery,
 * supplier invoices and the payments made against them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();         // PR-000123
            $table->string('department', 80);
            $table->string('status', 20)->default('submitted')->index(); // submitted, approved, rejected, ordered, cancelled
            $table->date('needed_by')->nullable();
            $table->string('reason', 500)->nullable();
            $table->decimal('estimated_total', 14, 2)->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 255)->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);              // estimate
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('total');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 255)->nullable()->after('approved_by');
        });

        Schema::table('stock_receipt_lines', function (Blueprint $table) {
            $table->decimal('rejected_quantity', 14, 3)->default(0);
            $table->string('reject_reason', 120)->nullable();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->default(30)->after('address');
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();         // SI-000123 (our reference)
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number', 60);                       // the supplier's number
            $table->foreignId('stock_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->index();
            $table->decimal('amount', 14, 2);
            $table->decimal('paid', 14, 2)->default(0);
            $table->string('status', 12)->default('unpaid')->index();   // unpaid, part_paid, paid, void
            $table->string('notes', 500)->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['supplier_id', 'invoice_number']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 20);                                // bank_transfer, cash, cheque, pos
            $table->string('reference', 80)->nullable();
            $table->date('paid_on');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoices');
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('payment_terms_days'));
        Schema::table('stock_receipt_lines', fn (Blueprint $table) => $table->dropColumn(['rejected_quantity', 'reject_reason']));
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['submitted_at', 'approved_at', 'rejection_reason']);
        });
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
    }
};
