<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guests, reservations, the rooms on each reservation, and an event log of
 * everything that happens to a reservation (spec §4 and §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('title', 20)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->nullable()->index();
            $table->string('phone', 30)->nullable()->index();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('id_type', 50)->nullable();       // passport, NIN, driver's licence…
            $table->string('id_number', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('company', 150)->nullable();
            $table->boolean('is_vip')->default(false);
            $table->text('preferences')->nullable();         // service preferences / special needs
            $table->text('notes')->nullable();               // internal only
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();       // e.g. SH-7K3M9Q
            $table->string('qr_token', 64)->unique();        // random; never contains personal data
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('confirmed')->index();
            $table->string('source', 20)->default('walk_in');
            $table->date('check_in_date')->index();
            $table->date('check_out_date')->index();
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->time('expected_arrival_time')->nullable();
            $table->decimal('room_total', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_reason', 255)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('special_requests')->nullable();    // seen by the guest / housekeeping
            $table->text('notes')->nullable();               // internal
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('reservation_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete(); // null until assigned
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->json('nightly_rates');                   // {"2026-10-23": 40000, …} snapshot at booking
            $table->decimal('amount', 12, 2);                // sum of nightly_rates
            $table->timestamps();

            $table->index(['room_id', 'reservation_id']);
        });

        Schema::create('reservation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);                     // created, confirmed, room_assigned, checked_in…
            $table->string('description', 1000);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['reservation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_events');
        Schema::dropIfExists('reservation_rooms');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('guests');
    }
};
