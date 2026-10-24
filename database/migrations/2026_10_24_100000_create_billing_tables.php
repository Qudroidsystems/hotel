<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest billing (spec §9): one folio per stay, immutable charge lines,
 * payments / deposits / refunds. Mistakes are corrected by voiding with a
 * reason — rows are never edited or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();      // F-000123
            $table->foreignId('reservation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('open')->index();  // open, closed
            $table->decimal('credit_limit', 12, 2)->nullable();      // for charge-to-room (POS)
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('folio_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->index();                     // room, food, beverage, laundry, … discount
            $table->string('description', 255);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);                        // quantity × unit price (negative for discounts)
            $table->decimal('tax_amount', 12, 2)->default(0);        // added on top when prices exclude tax
            $table->json('tax_breakdown')->nullable();               // [{name, rate, amount}] snapshot
            $table->boolean('tax_inclusive')->default(true);
            $table->decimal('total', 12, 2);                         // what the guest owes for this line
            $table->date('service_date')->index();                   // business date / room night
            $table->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('source');                        // e.g. a POS order later
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            // A room night can only be posted once per booked room.
            $table->unique(['reservation_room_id', 'service_date', 'type'], 'folio_room_night_unique');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 20)->nullable()->unique(); // R-000123
            $table->foreignId('folio_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10)->default('payment');          // payment, deposit, refund
            $table->string('method', 20);                            // cash, transfer, card, online, cheque, other
            $table->decimal('amount', 12, 2);                        // positive; refunds reduce what's been paid
            $table->string('reference', 100)->nullable();            // transfer ref / POS approval code
            $table->string('notes', 255)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->index();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['method', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('folio_charges');
        Schema::dropIfExists('folios');
    }
};
