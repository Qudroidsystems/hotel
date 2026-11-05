<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card terminals linked to the system (Paystack Terminal, Moniepoint, OPay):
 * staff send the amount to the terminal from the bill, the till or the staff
 * app, the guest taps or inserts their card, and the payment is recorded by
 * itself once the provider confirms it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 80);                              // "Front desk 1", "Pool bar"
            $table->string('provider', 20);                          // paystack | moniepoint | opay | demo
            $table->string('identifier', 80);                        // Paystack terminal ID, Moniepoint / OPay serial number
            $table->foreignId('pos_outlet_id')->nullable()->constrained('pos_outlets')->nullOnDelete();
            $table->string('location', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('last_online')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'identifier']);
        });

        Schema::create('terminal_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('payment_terminal_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique();               // ours, sent to the provider
            $table->decimal('amount', 12, 2);
            $table->string('status', 12)->default('sending')->index(); // sending | waiting | paid | failed | cancelled | expired
            $table->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pos_outlet_id')->nullable()->constrained('pos_outlets')->nullOnDelete();
            $table->string('description', 150)->nullable();
            $table->string('provider_ref', 120)->nullable()->index(); // Paystack request code, OPay order no.
            $table->string('provider_id', 60)->nullable();           // Paystack payment request id
            $table->string('approval', 120)->nullable();             // the provider's transaction reference / RRN once paid
            $table->string('card', 60)->nullable();                  // "VISA •••• 4081"
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('message', 255)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();  // the bill payment it became
            $table->uuid('pos_order_uuid')->nullable();              // the till sale it paid
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 10)->default('web');           // web | pos | app
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_payments');
        Schema::dropIfExists('payment_terminals');
    }
};
