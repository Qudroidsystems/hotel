<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest loyalty: a member number, a points balance and tier on each guest,
 * and a ledger of every point earned, spent or adjusted. Also the guest's
 * choices about messages (used by SMS / WhatsApp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('loyalty_number', 20)->nullable()->unique()->after('is_vip');
            $table->integer('loyalty_points')->default(0)->after('loyalty_number');
            $table->string('loyalty_tier', 20)->default('member')->after('loyalty_points');
            $table->boolean('messages_opt_out')->default(false)->after('loyalty_tier');   // no SMS / WhatsApp except replies they asked for
            $table->boolean('marketing_opt_in')->default(false)->after('messages_opt_out');
        });

        Schema::create('loyalty_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('folio_charge_id')->nullable()->constrained('folio_charges')->nullOnDelete();
            $table->string('type', 12);                 // earn | redeem | adjust
            $table->integer('points');                  // + earned / added, − spent / taken away
            $table->decimal('amount', 12, 2)->nullable(); // the spend it was earned on, or the money value spent
            $table->integer('balance_after');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['guest_id', 'created_at']);
            $table->index(['reservation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_entries');
        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique(['loyalty_number']);
            $table->dropColumn(['loyalty_number', 'loyalty_points', 'loyalty_tier', 'messages_opt_out', 'marketing_opt_in']);
        });
    }
};
