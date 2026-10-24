<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms & property: buildings → floors → rooms, room types (rates,
 * capacity, amenities, photos) and a log of every room status change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->string('name');                       // "Ground Floor", "First Floor"
            $table->smallInteger('level')->default(0);    // 0 = ground, -1 = basement
            $table->timestamps();

            $table->unique(['building_id', 'level']);
        });

        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // Standard, Deluxe, Executive, Suite
            $table->string('code', 20)->unique();
            $table->text('description')->nullable();
            $table->decimal('base_rate', 12, 2);          // per night, weekdays
            $table->decimal('weekend_rate', 12, 2)->nullable(); // Fri & Sat nights; null = base rate
            $table->unsignedTinyInteger('max_adults')->default(2);
            $table->unsignedTinyInteger('max_children')->default(0);
            $table->string('bed_type', 50)->nullable();   // King, Queen, Twin…
            $table->unsignedSmallInteger('size_sqm')->nullable();
            $table->json('amenities')->nullable();
            $table->string('color', 7)->default('#405189'); // shown on the room board
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('room_type_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();       // "101", "B-204"
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('floor_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('available')->index();
            $table->decimal('rate_override', 12, 2)->nullable(); // this room only; null = room type rate
            $table->json('features')->nullable();          // room-specific extras (sea view, balcony…)
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);   // inactive = not sold at all
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('room_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_status_logs');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_type_photos');
        Schema::dropIfExists('room_types');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
    }
};
