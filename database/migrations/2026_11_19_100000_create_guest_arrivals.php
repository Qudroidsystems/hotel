<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arrival sharing: a guest on the way can let the hotel see how far away they
 * are, so the room is ready and someone meets them. Only the latest position
 * is kept, and it is wiped when sharing stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_arrivals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('sharing');         // sharing, arrived, stopped
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->unsignedInteger('start_distance_m')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->unsignedSmallInteger('eta_minutes')->nullable();
            $table->string('stop_reason', 40)->nullable();             // guest, checked_in, timed_out, cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('near_alerted_at')->nullable();
            $table->timestamp('here_alerted_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_arrivals');
    }
};
