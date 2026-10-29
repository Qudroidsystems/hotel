<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest chat and calls: guests message or call a section of the hotel
 * (Reception, Restaurant, Bar, Laundry …) from the guest app or their booking
 * page; the section's staff answer on the website or in the staff app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('key', 30);                          // reception, restaurant, bar, laundry …
            $table->string('name', 60);
            $table->string('icon', 40)->default('ri-chat-3-line');
            $table->string('description', 160)->nullable();
            $table->string('phone', 40)->nullable();           // a normal phone number, always offered too
            $table->string('opens', 5)->nullable();            // "07:00"; empty = always
            $table->string('closes', 5)->nullable();
            $table->boolean('calls')->default(true);           // guests can call in the app
            $table->json('roles')->nullable();                 // staff roles who answer
            $table->json('users')->nullable();                 // and these people
            $table->string('away_message', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['hotel_id', 'key']);
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('section_id')->constrained('chat_sections')->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('open');     // open, closed
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('guest_unread')->default(0);   // staff messages the guest hasn't seen
            $table->unsignedInteger('staff_unread')->default(0);   // guest messages staff haven't seen
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_preview', 160)->nullable();
            $table->timestamp('guest_typing_at')->nullable();
            $table->timestamp('staff_typing_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['section_id', 'reservation_id']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('sender', 8);                       // guest, staff, system
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body')->nullable();
            $table->string('photo', 255)->nullable();
            $table->uuid('client_id')->nullable();             // the sender's own id: a resend is stored once
            $table->timestamp('read_at')->nullable();          // seen by the other side
            $table->timestamps();
            $table->unique(['conversation_id', 'client_id']);
        });

        Schema::create('guest_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('direction', 8)->default('in');     // in: guest calls the hotel; out: staff call the guest back
            $table->string('status', 10)->default('ringing');  // ringing, active, ended, missed, declined, failed
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('guest_call_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('guest_calls')->cascadeOnDelete();
            $table->string('side', 6);                         // guest, staff
            $table->string('kind', 12);                        // offer, answer, candidate, hangup
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        if (Schema::hasTable('permissions')) {
            foreach (['Chat with guests', 'Manage guest chat'] as $name) {
                DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['title' => 'Guest Services', 'updated_at' => now(), 'created_at' => now()]);
            }
            $perm = fn ($n) => DB::table('permissions')->where('name', $n)->where('guard_name', 'web')->value('id');
            $grants = [
                'General Manager' => ['Chat with guests', 'Manage guest chat'], 'Front Desk' => ['Chat with guests'], 'Bar Manager' => ['Chat with guests'],
                'Cashier' => ['Chat with guests'], 'Housekeeping Supervisor' => ['Chat with guests'], 'Housekeeping' => ['Chat with guests'],
            ];
            foreach ($grants as $role => $names) {
                $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
                foreach ($names as $n) {
                    if ($roleId && ($pid = $perm($n))) {
                        DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $pid, 'role_id' => $roleId]);
                    }
                }
            }
            app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_call_signals');
        Schema::dropIfExists('guest_calls');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('chat_sections');
    }
};
