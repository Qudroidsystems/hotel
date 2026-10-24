<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bar & restaurant point of sale (spec §8).
 *
 * Built to keep selling when the network drops: each till creates its orders
 * on the device with a random `uuid` and its own order number, and sends them
 * when it can. The server accepts each uuid once, so a sale that is sent
 * twice (retry after a timeout) is never recorded twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_outlets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 10)->unique();                       // BAR, RES, POOL — starts each order number
            $table->string('type', 20)->default('bar');                 // bar, restaurant, room_service, pool, lounge, other
            $table->string('color', 9)->default('#0d9488');
            $table->boolean('allow_room_charge')->default(true);
            $table->string('receipt_footer', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('color', 9)->default('#475569');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('pos_categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('sku', 40)->nullable()->unique();
            $table->decimal('price', 12, 2);
            $table->string('charge_type', 20)->default('food');         // how it appears on a guest bill: food, beverage, minibar, service, other
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_item_outlet', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained('pos_items')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('pos_outlets')->cascadeOnDelete();
            $table->primary(['item_id', 'outlet_id']);
        });

        Schema::create('pos_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                              // made on the till; the duplicate guard
            $table->string('number', 40)->unique();                      // BAR-K7Q2-0042 (outlet-device-sequence)
            $table->foreignId('outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->string('status', 20)->index();                       // paid, charged (to a room), attention, voided
            $table->string('method', 20)->nullable();                    // cash, card, transfer, … or room
            $table->foreignId('bank_account_id')->nullable()->constrained('hotel_bank_accounts')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $table->string('room_number', 20)->nullable();               // as chosen on the till
            $table->string('guest_name', 120)->nullable();
            $table->string('table_label', 40)->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_reason', 255)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);            // added on top when prices exclude tax
            $table->json('tax_breakdown')->nullable();
            $table->decimal('total', 12, 2);
            $table->decimal('tendered', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('synced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_id', 20)->nullable();
            $table->timestamp('ordered_at')->index();                    // the till's clock
            $table->timestamp('synced_at')->nullable();                  // when the server got it
            $table->boolean('was_offline')->default(false);
            $table->string('sync_note', 255)->nullable();                // anything the server had to flag
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'ordered_at']);
            $table->index(['cashier_id', 'ordered_at']);
        });

        Schema::create('pos_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('pos_items')->nullOnDelete();
            $table->string('name', 120);                                 // snapshot
            $table->string('charge_type', 20);
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);                        // price charged on the till
            $table->decimal('total', 12, 2);
            $table->string('note', 120)->nullable();                     // "no ice", "well done"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_items');
        Schema::dropIfExists('pos_orders');
        Schema::dropIfExists('pos_item_outlet');
        Schema::dropIfExists('pos_items');
        Schema::dropIfExists('pos_categories');
        Schema::dropIfExists('pos_outlets');
    }
};
