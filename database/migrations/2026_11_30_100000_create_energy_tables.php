<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Energy Saver: from what the system already knows (who is in which room, who arrives when, which halls are
 * booked, which outlets are open) it works out which rooms and places can be switched off, which to switch on
 * before a guest arrives, how much power the hotel needs over the next day and which generator is the smallest
 * that carries it. Where the equipment board knows what is actually on, only equipment really left running is
 * flagged, and ticking a suggestion off switches it on the board. The control room is told; they tick off what
 * they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Places other than guest rooms: lobby, corridors, kitchen, laundry, pool pumps, halls, outlets …
        Schema::create('energy_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 80);
            $table->string('kind', 20)->default('common');     // common, corridor, hall, outlet, service, outdoor, office
            $table->foreignId('floor_id')->nullable()->constrained('floors')->nullOnDelete();     // a corridor: off when its floor is empty
            $table->foreignId('hall_id')->nullable()->constrained('halls')->nullOnDelete();       // on around its events
            $table->foreignId('outlet_id')->nullable()->constrained('pos_outlets')->nullOnDelete(); // on in its opening hours
            $table->unsignedInteger('watts')->default(2000);   // what it draws when on
            $table->unsignedInteger('low_watts')->default(0);  // when switched down (security lights, fridges …)
            $table->string('opens', 5)->nullable();            // "06:00"; empty = by its hall / outlet / floor, or always
            $table->string('closes', 5)->nullable();
            $table->boolean('always_on')->default(false);      // never suggested (server room, cold room, security)
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Where power comes from: the grid, generators, solar / inverter.
        Schema::create('energy_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 80);
            $table->string('kind', 12)->default('generator');  // grid, generator, solar, inverter
            $table->unsignedInteger('capacity_kw')->default(0);
            $table->decimal('litres_per_hour', 8, 2)->nullable(); // diesel at full load (empty: estimated)
            $table->unsignedInteger('tank_litres')->nullable();
            $table->decimal('fuel_litres', 9, 1)->nullable();  // last known level
            $table->timestamp('fuel_at')->nullable();
            $table->foreignId('device_id')->nullable()->constrained('facility_devices')->nullOnDelete(); // its switch on the equipment board: on/off read from there
            $table->boolean('running')->default(false);        // the grid: power is on
            $table->timestamp('running_since')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Power changes (grid off, generator started …) and fuel readings, for the history and run hours.
        Schema::create('energy_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('source_id')->nullable()->constrained('energy_sources')->nullOnDelete();
            $table->string('kind', 20);                         // on, off, fuel
            $table->decimal('value', 9, 1)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('by', 10)->default('staff');         // staff, device, system
            $table->timestamp('created_at')->nullable();
        });

        // What the system suggested and what the control room did about it.
        Schema::create('energy_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('key', 80);                          // room:12:off, zone:3:off, source:2:start …
            $table->string('target', 10);                       // room, zone, floor, source
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('action', 12);                       // off, low, on, precool, start, stop
            $table->string('title', 160);
            $table->string('reason', 255)->nullable();
            $table->unsignedInteger('watts')->default(0);       // saved (off/low) or needed (on)
            $table->json('devices')->nullable();               // equipment-board devices to switch (ids)
            $table->timestamp('until')->nullable();             // when it is needed again
            $table->string('status', 10)->default('open');      // open, done, skipped, expired
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('kwh_saved', 9, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'key']);
        });

        // Rooms: a sensor or key-card switch can say whether anyone is in.
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('presence', 8)->nullable()->after('status');   // occupied, empty
            $table->timestamp('presence_at')->nullable()->after('presence');
        });

        if (Schema::hasTable('permissions')) {
            foreach (['View energy saver', 'Manage energy saver'] as $name) {
                DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['title' => 'Energy', 'updated_at' => now(), 'created_at' => now()]);
            }
            $perm = fn ($n) => DB::table('permissions')->where('name', $n)->where('guard_name', 'web')->value('id');
            $grants = [
                'General Manager' => ['View energy saver', 'Manage energy saver'], 'IT Operations' => ['View energy saver', 'Manage energy saver'],
                'Maintenance Technician' => ['View energy saver'], 'Security Officer' => ['View energy saver'], 'Hotel Owner' => ['View energy saver'],
                'Front Desk' => ['View energy saver'], 'Housekeeping Supervisor' => ['View energy saver'],
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
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn(['presence', 'presence_at']));
        Schema::dropIfExists('energy_actions');
        Schema::dropIfExists('energy_events');
        Schema::dropIfExists('energy_sources');
        Schema::dropIfExists('energy_zones');
    }
};
