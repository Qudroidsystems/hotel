<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online booking and guest self-service (spec §4, §5, §12.2; Release 3).
 *  - reservations.guest_token: the guest's private link to their booking
 *    (separate from the check-in QR token, so sharing one never exposes the other).
 *  - reservations.hold_expires_at: an online booking waiting for its deposit is
 *    released automatically after this time.
 *  - online_payments: every gateway transaction, for reconciliation (spec §9).
 *  - guest_requests: service requests sent from the guest portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('guest_token', 64)->nullable()->unique()->after('qr_token');
            $table->timestamp('hold_expires_at')->nullable()->index()->after('guest_token');
        });

        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('paystack');
            $table->string('reference', 80)->unique();                 // ours, sent to the gateway
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('purpose', 20)->default('deposit');          // deposit, balance
            $table->string('status', 15)->default('initialized')->index(); // initialized, success, failed, abandoned
            $table->string('email', 150)->nullable();
            $table->string('channel', 30)->nullable();                  // card, bank, ussd, transfer…
            $table->string('gateway_id', 40)->nullable();               // the gateway's transaction id
            $table->string('message', 255)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();                           // trimmed gateway response
            $table->timestamps();
        });

        Schema::create('guest_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->index();                        // towels, cleaning, amenities, repair, room_service, wake_up, transport, other
            $table->string('details', 1000)->nullable();
            $table->string('status', 15)->default('open')->index();     // open, in_progress, done, cancelled
            $table->foreignId('housekeeping_task_id')->nullable()->constrained('housekeeping_tasks')->nullOnDelete();
            $table->foreignId('maintenance_order_id')->nullable()->constrained('maintenance_orders')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('staff_note', 500)->nullable();              // shown to the guest
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_requests');
        Schema::dropIfExists('online_payments');
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['guest_token']);
            $table->dropColumn(['guest_token', 'hold_expires_at']);
        });
    }
};
