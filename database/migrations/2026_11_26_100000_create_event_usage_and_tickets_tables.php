<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events, on the day and at the door: when the hall was handed over and given back, how many came, the state it was
 * left in; and tickets for events the public pays to attend (ticket types, orders, and one QR ticket per seat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'checked_in_at')) {
                $table->dateTime('checked_in_at')->nullable()->after('cancelled_at');            // hall handed over
                $table->foreignId('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
                $table->dateTime('checked_out_at')->nullable()->after('checked_in_by');          // hall given back
                $table->foreignId('checked_out_by')->nullable()->after('checked_out_at')->constrained('users')->nullOnDelete();
                $table->unsignedSmallInteger('guests_actual')->nullable()->after('guests_expected');
                $table->string('hall_condition', 10)->nullable()->after('checked_out_by');      // good | minor | damaged
                $table->string('usage_notes', 1000)->nullable()->after('hall_condition');
                $table->boolean('is_ticketed')->default(false)->after('usage_notes');
                $table->boolean('ticket_sales_open')->default(false)->after('is_ticketed');
                $table->string('ticket_info', 1000)->nullable()->after('ticket_sales_open');     // shown to buyers
            }
        });

        if (! Schema::hasTable('event_ticket_types')) {
            Schema::create('event_ticket_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->nullable()->index();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);                                      // Regular, VIP, Table for 10
                $table->string('description', 255)->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->unsignedSmallInteger('admits')->default(1);              // people per ticket (a table for 10 admits 10)
                $table->unsignedInteger('quantity')->nullable();                 // tickets for sale; null = up to the hall's capacity
                $table->unsignedSmallInteger('max_per_order')->default(10);
                $table->dateTime('sales_start_at')->nullable();
                $table->dateTime('sales_end_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('event_ticket_orders')) {
            Schema::create('event_ticket_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->nullable()->index();
                $table->string('number', 20)->nullable()->unique();              // TO-000001
                $table->foreignId('event_id')->constrained()->restrictOnDelete();
                $table->string('channel', 10)->default('desk');                  // desk | app
                $table->string('status', 10)->default('paid')->index();          // paid | cancelled
                $table->string('buyer_name', 120);
                $table->string('buyer_phone', 40)->nullable();
                $table->string('buyer_email', 150)->nullable();
                $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();   // charged to this room
                $table->foreignId('folio_charge_id')->nullable()->constrained('folio_charges')->nullOnDelete();
                $table->unsignedSmallInteger('quantity');
                $table->decimal('total', 14, 2);
                $table->string('method', 20);                                    // a payment method, or "room"
                $table->string('reference', 100)->nullable();
                $table->foreignId('bank_account_id')->nullable()->constrained('hotel_bank_accounts')->nullOnDelete();
                $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('paid_at')->index();
                $table->dateTime('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('cancel_reason', 255)->nullable();
                $table->string('idempotency_key', 64)->nullable()->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('event_tickets')) {
            Schema::create('event_tickets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->nullable()->index();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->constrained('event_ticket_orders')->cascadeOnDelete();
                $table->foreignId('ticket_type_id')->constrained('event_ticket_types')->restrictOnDelete();
                $table->string('code', 20)->unique();                            // on the QR code
                $table->decimal('price', 12, 2);
                $table->string('status', 8)->default('valid')->index();          // valid | used | void
                $table->dateTime('checked_in_at')->nullable();
                $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['event_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tickets');
        Schema::dropIfExists('event_ticket_orders');
        Schema::dropIfExists('event_ticket_types');
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'checked_in_at')) {
                $table->dropConstrainedForeignId('checked_in_by');
                $table->dropConstrainedForeignId('checked_out_by');
                $table->dropColumn(['checked_in_at', 'checked_out_at', 'guests_actual', 'hall_condition', 'usage_notes', 'is_ticketed', 'ticket_sales_open', 'ticket_info']);
            }
        });
    }
};
