<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest's luggage list (optional): what they bring, written when booking
 * or any time before they leave. On check-out day they tick each item off as
 * they pack, and the hotel reminds them to go through it so nothing is left
 * in the room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luggage_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('bag', 60)->nullable();              // which bag it is in: "Black suitcase", "Backpack"
            $table->timestamp('packed_at')->nullable();         // ticked off when packing to leave
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['reservation_id', 'position']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('luggage_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('luggage_reminded_at');
        });
        Schema::dropIfExists('luggage_items');
    }
};
