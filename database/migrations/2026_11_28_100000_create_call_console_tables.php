<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The IT/security call console (IT Control Room → Console):
 *  - guest calls can be picked up by an operator, transferred (each hand-over is a new "leg" of the
 *    call: the guest's phone connects again to whoever takes it) and listened to by a supervisor;
 *  - voice messages in guest chat;
 *  - the on/off board of every room's equipment and the hotel's other areas, fed by hand or by
 *    hardware (a building-management or IoT hub posting to /api/v1/facility/report).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_calls', function (Blueprint $table) {
            $table->unsignedSmallInteger('leg')->default(1)->after('status');
            $table->timestamp('rang_at')->nullable()->after('leg');            // when it started ringing again (transfers)
            $table->foreignId('transfer_to_user')->nullable()->after('started_by')->constrained('users')->nullOnDelete();
            $table->foreignId('transfer_to_section')->nullable()->after('transfer_to_user')->constrained('chat_sections')->nullOnDelete();
            $table->foreignId('transferred_by')->nullable()->after('transfer_to_section')->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable()->after('transferred_by');
            $table->string('transfer_note', 160)->nullable()->after('transferred_at');
        });

        Schema::table('guest_call_signals', function (Blueprint $table) {
            $table->unsignedSmallInteger('leg')->default(1)->after('call_id');
            $table->unsignedBigInteger('listen_id')->nullable()->after('leg')->index();
        });

        // A supervisor listening in on a call (the voice comes from the hotel side's browser).
        Schema::create('call_listens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('call_id')->constrained('guest_calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('leg')->default(1);
            $table->string('status', 10)->default('waiting');               // waiting, live, ended
            $table->string('reason', 255)->nullable();                      // why they listened (required, audited)
            $table->timestamp('live_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->timestamps();
            $table->index(['call_id', 'status']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('audio', 255)->nullable()->after('photo');         // a voice message (private disk)
            $table->unsignedSmallInteger('audio_seconds')->nullable()->after('audio');
        });

        Schema::create('facility_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('room_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('area', 60)->nullable();                         // for places that aren't rooms: Lobby, Kitchen, Pool …
            $table->string('name', 80);
            $table->string('kind', 30);                                     // ac, tv, lights, fridge, water_heater, door_lock …
            $table->string('code', 60);                                     // what hardware calls it ("R101-AC")
            $table->string('state', 10)->default('off');                    // on, off, fault
            $table->string('reading', 30)->nullable();                      // "22°C", "78%", "3.2 kW"
            $table->string('source', 10)->default('manual');                // manual, hub
            $table->boolean('is_critical')->default(false);                 // off = an alarm (generator, pump, fire panel)
            $table->boolean('should_be_on')->default(false);                // expected on all the time
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('notes', 255)->nullable();
            $table->timestamp('state_changed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();                  // the hub last reported it
            $table->timestamps();
            $table->unique(['hotel_id', 'code']);
            $table->index(['room_id', 'is_active']);
        });

        Schema::create('facility_device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('facility_devices')->cascadeOnDelete();
            $table->string('from_state', 10)->nullable();
            $table->string('state', 10);
            $table->string('reading', 30)->nullable();
            $table->string('source', 10);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['device_id', 'created_at']);
        });

        // Hardware gateways allowed to report device states (the key is shown once, stored hashed).
        Schema::create('facility_hubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique();
            $table->string('token_hint', 8);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_hubs');
        Schema::dropIfExists('facility_device_events');
        Schema::dropIfExists('facility_devices');
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropColumn(['audio', 'audio_seconds']));
        Schema::dropIfExists('call_listens');
        Schema::table('guest_call_signals', function (Blueprint $t) {
            $t->dropIndex(['listen_id']);
            $t->dropColumn(['leg', 'listen_id']);
        });
        Schema::table('guest_calls', function (Blueprint $t) {
            $t->dropConstrainedForeignId('transfer_to_user');
            $t->dropConstrainedForeignId('transfer_to_section');
            $t->dropConstrainedForeignId('transferred_by');
            $t->dropColumn(['leg', 'rang_at', 'transferred_at', 'transfer_note']);
        });
    }
};
