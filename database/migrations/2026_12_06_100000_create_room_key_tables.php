<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Digital room keys: each room's lock (its id in the lock maker's system), the keys given for a stay (a door PIN,
 * a key card, the phone), and the door log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('lock_id', 60)->nullable()->after('guest_code');   // the lock's id with the lock provider
        });

        Schema::create('room_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 6);                                   // pin, card, phone
            $table->text('secret')->nullable();                          // the PIN (encrypted)
            $table->string('card_number', 40)->nullable();
            $table->string('provider', 12)->default('manual');           // manual, ttlock, bridge
            $table->string('provider_ref', 80)->nullable();              // the provider's id for this key
            $table->dateTime('valid_from');
            $table->dateTime('valid_until');
            $table->string('status', 8)->default('active');              // active, revoked, expired, lost, failed
            $table->string('note', 255)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['room_id', 'status']);
            $table->index(['reservation_id', 'status']);
        });

        Schema::create('door_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('key_id')->nullable()->constrained('room_keys')->nullOnDelete();
            $table->string('kind', 12);                                  // phone, remote, pin, card, key, other
            $table->string('who', 120)->nullable();
            $table->boolean('ok')->default(true);
            $table->string('note', 255)->nullable();
            $table->string('provider_ref', 80)->nullable();              // a lock record's id, so a sync never adds it twice
            $table->timestamp('at')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['room_id', 'provider_ref']);
        });

        if (Schema::hasTable('permissions')) {
            foreach (['Manage room keys', 'View door log'] as $name) {
                DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['title' => 'Rooms', 'updated_at' => now(), 'created_at' => now()]);
            }
            $perm = fn ($n) => DB::table('permissions')->where('name', $n)->where('guard_name', 'web')->value('id');
            $grants = ['General Manager' => ['Manage room keys', 'View door log'], 'Front Desk' => ['Manage room keys'], 'Security Officer' => ['View door log'], 'IT Operations' => ['Manage room keys', 'View door log']];
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
        Schema::dropIfExists('door_events');
        Schema::dropIfExists('room_keys');
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn('lock_id'));
        DB::table('permissions')->whereIn('name', ['Manage room keys', 'View door log'])->delete();
    }
};
