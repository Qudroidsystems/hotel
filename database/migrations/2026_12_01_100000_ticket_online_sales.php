<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event tickets, sold to anyone: online with Paystack (an order is held for a few minutes while the buyer pays),
 * early-bird prices, promo codes, and the tickets e-mailed to the buyer with a link to their QR codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_ticket_types', function (Blueprint $table) {
            $table->decimal('early_price', 12, 2)->nullable()->after('price');       // early-bird price …
            $table->dateTime('early_until')->nullable()->after('early_price');      // … until then
        });

        Schema::create('event_ticket_promos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('kind', 8)->default('percent');                 // percent | amount (off the order)
            $table->decimal('value', 12, 2);
            $table->unsignedInteger('max_uses')->nullable();               // orders; null = no limit
            $table->unsignedInteger('used')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['event_id', 'code']);
        });

        Schema::table('event_ticket_orders', function (Blueprint $table) {
            $table->dateTime('paid_at')->nullable()->change();               // pending online orders aren't paid yet
            $table->string('token', 48)->nullable()->unique()->after('number');   // the buyer's link to their tickets
            $table->json('lines')->nullable()->after('quantity');            // {type_id: quantity} while pending
            $table->json('prices')->nullable()->after('lines');              // {type_id: price each} when ordered (early-bird …)
            $table->decimal('discount', 12, 2)->default(0)->after('total');
            $table->foreignId('promo_id')->nullable()->after('discount')->constrained('event_ticket_promos')->nullOnDelete();
            $table->string('gateway_reference', 60)->nullable()->unique()->after('reference');   // Paystack
            $table->dateTime('expires_at')->nullable()->after('paid_at');    // a pending order lets its tickets go then
            $table->dateTime('emailed_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_ticket_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_id');
            $table->dropUnique(['token']);
            $table->dropUnique(['gateway_reference']);
            $table->dropColumn(['token', 'lines', 'prices', 'discount', 'gateway_reference', 'expires_at', 'emailed_at']);
        });
        Schema::dropIfExists('event_ticket_promos');
        Schema::table('event_ticket_types', fn (Blueprint $table) => $table->dropColumn(['early_price', 'early_until']));
    }
};
