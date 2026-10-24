<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory & stock (spec §10).
 *
 * Every change to a stock quantity is a row in `stock_movements` — the ledger.
 * Rows are never edited or deleted; mistakes are corrected with another
 * movement. `stock_levels` is just the running balance per item per store,
 * kept in step by StockService inside the same database transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 12)->unique();
            $table->string('type', 20)->default('store');           // store, bar, kitchen, housekeeping, other
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('stock_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('stock_categories')->nullOnDelete();
            $table->string('name', 120);
            $table->string('sku', 40)->nullable()->unique();
            $table->string('unit', 20)->default('pcs');              // bottle, crate, kg, litre, pcs, pack…
            $table->decimal('reorder_level', 14, 3)->default(0);     // warn when total on hand falls to this
            $table->decimal('reorder_qty', 14, 3)->nullable();       // suggested order quantity
            $table->decimal('avg_cost', 14, 4)->default(0);          // weighted average cost per unit
            $table->decimal('last_cost', 14, 4)->nullable();
            $table->foreignId('preferred_supplier_id')->nullable();  // constrained after suppliers exists
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->cascadeOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);          // may go below zero (sales rung up offline)
            $table->timestamps();
            $table->unique(['item_id', 'location_id']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact_person', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('stock_items', function (Blueprint $table) {
            $table->foreign('preferred_supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();      // PO-000123
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete(); // deliver to
            $table->string('status', 20)->default('draft')->index(); // draft, ordered, partial, received, cancelled
            $table->date('expected_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ordered_at')->nullable();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('received_quantity', 14, 3)->default(0);
        });

        // Goods received notes.
        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();      // GRN-000123
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('supplier_invoice', 60)->nullable();
            $table->date('received_on');
            $table->decimal('total', 14, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();      // TRF-000123
            $table->foreignId('from_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('notes', 500)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
        });

        // Stocktakes: the system quantity is frozen when the count starts; posting books the difference.
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();      // CNT-000123
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('stock_categories')->nullOnDelete();
            $table->string('status', 20)->default('counting')->index(); // counting, posted, cancelled
            $table->string('notes', 500)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('variance_value', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('expected_quantity', 14, 3);             // system quantity when the count started
            $table->decimal('counted_quantity', 14, 3)->nullable();  // null = not counted yet
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->unique(['stock_count_id', 'item_id']);
        });

        // The ledger.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('type', 20)->index();                     // opening, receipt, transfer_in, transfer_out, sale, sale_void, issue, waste, adjustment, count
            $table->decimal('quantity', 14, 3);                      // + in, − out
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('value', 14, 2)->default(0);             // quantity × unit cost (signed)
            $table->decimal('balance_after', 14, 3);                 // this store's quantity after the movement
            $table->nullableMorphs('source');                        // receipt, transfer, count, POS order…
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['item_id', 'location_id', 'occurred_at']);
        });

        // Recipes: what one POS item uses from stock.
        Schema::create('pos_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_item_id')->constrained('pos_items')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);                      // per one sold
            $table->unique(['pos_item_id', 'stock_item_id']);
        });

        // Which store each outlet sells from.
        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->foreignId('stock_location_id')->nullable()->after('type')->constrained('stock_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_location_id');
        });
        Schema::dropIfExists('pos_item_components');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_receipt_lines');
        Schema::dropIfExists('stock_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropForeign(['preferred_supplier_id']);
        });
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('stock_categories');
        Schema::dropIfExists('stock_locations');
    }
};
