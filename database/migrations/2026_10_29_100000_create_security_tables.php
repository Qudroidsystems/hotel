<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security (spec §10, §12.3): visitor register with temporary QR passes,
 * incident reports with a timeline, lost & found with claim handover, and
 * an emergency contact directory. Visitor personal details are cleared after
 * the retention period (security:prune-visitors).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('pass_code', 16)->unique();                 // printed as a QR on the pass; random
            $table->string('full_name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('id_type', 30)->nullable();                 // NIN, driver's licence, passport…
            $table->string('id_number', 50)->nullable();
            $table->string('company', 120)->nullable();
            $table->string('purpose', 30);                             // guest_visit, meeting, delivery, contractor, interview, other
            $table->string('host', 150)->nullable();                   // who / which department they are seeing
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete(); // visiting an in-house guest
            $table->string('vehicle_plate', 20)->nullable();
            $table->unsignedTinyInteger('party_size')->default(1);
            $table->string('notes', 500)->nullable();
            $table->timestamp('signed_in_at')->index();
            $table->foreignId('signed_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expected_out_at')->nullable();           // pass stops being valid after this
            $table->timestamp('signed_out_at')->nullable()->index();
            $table->foreignId('signed_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anonymised_at')->nullable();
            $table->timestamps();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();        // INC-000123
            $table->string('type', 30)->index();
            $table->string('severity', 10)->index();                   // low, medium, high, critical
            $table->string('status', 20)->default('open')->index();    // open, investigating, resolved, closed
            $table->string('title', 150);
            $table->text('description');
            $table->string('location', 120)->nullable();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at')->index();
            $table->string('people_involved', 500)->nullable();
            $table->string('police_reference', 60)->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('photos')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->nullable();                  // set when the update changed the status
            $table->text('note');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('lost_items', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();        // LF-000123
            $table->string('description', 255);
            $table->string('category', 30);                            // phone, electronics, documents, jewellery, clothing, bag, money, keys, other
            $table->boolean('valuable')->default(false);
            $table->timestamp('found_at');
            $table->string('found_location', 120);
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('found_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_location', 120)->nullable();       // "Front desk safe, bag 3"
            $table->string('status', 12)->default('stored')->index(); // stored, claimed, disposed
            $table->string('claimant_name', 150)->nullable();
            $table->string('claimant_contact', 60)->nullable();
            $table->string('claimant_id', 80)->nullable();             // ID checked at handover
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disposal_note', 255)->nullable();          // donated, destroyed, sent to police…
            $table->string('photo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('role', 120)->nullable();                   // Fire service, Duty manager, Electrician on call
            $table->string('phone', 40);
            $table->string('alt_phone', 40)->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('lost_items');
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('visitors');
    }
};
