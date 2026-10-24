<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier shifts (spec §7.3, §9 daily closing): a cashier opens a drawer with
 * a float, money paid in or out during the shift is recorded with a reason,
 * and at close the counted cash is compared with what the system expected.
 * The expected figures are frozen at close so later voids don't rewrite them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();       // SH-000123
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('station', 60);                            // Main bar till, Front desk…
            $table->foreignId('outlet_id')->nullable()->constrained('pos_outlets')->nullOnDelete();
            $table->string('status', 12)->default('open')->index();   // open, closed, reviewed
            $table->decimal('opening_float', 12, 2)->default(0);
            $table->timestamp('opened_at')->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->decimal('expected_cash', 12, 2)->nullable();      // frozen at close
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();           // counted − expected
            $table->json('summary')->nullable();                      // frozen takings by method at close
            $table->json('denominations')->nullable();                // optional note/coin count
            $table->string('close_note', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('cash_shift_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_shift_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);                               // paid_in, paid_out, drop (to the safe)
            $table->decimal('amount', 12, 2);                         // always positive
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_shift_movements');
        Schema::dropIfExists('cash_shifts');
    }
};
