<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hotel's car service: its cars, its drivers, the places it drives to at a
 * set fare (the airport …), and rides guests book from the guest app or their
 * booking page — picked up anywhere and brought to the hotel, or taken out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 80);                       // Toyota Camry
            $table->string('plate', 20);
            $table->string('color', 30)->nullable();
            $table->string('kind', 12)->default('sedan');     // sedan, suv, van, bus
            $table->unsignedTinyInteger('seats')->default(4);
            $table->string('status', 16)->default('available'); // available, out_of_service
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['hotel_id', 'plate']);
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // their staff-app login
            $table->string('name', 100);
            $table->string('phone', 30);
            $table->string('licence_number', 40)->nullable();
            $table->date('licence_expires')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete(); // usual car
            $table->boolean('on_duty')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ride_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 80);                       // Murtala Muhammed Airport (LOS)
            $table->string('kind', 12)->default('other');     // airport, station, other
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('fare', 12, 2)->nullable();       // set fare to or from the hotel
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('rides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name', 120)->nullable();
            $table->string('guest_phone', 30)->nullable();
            $table->string('direction', 10)->default('to_hotel');   // to_hotel (pick me up), from_hotel
            $table->foreignId('place_id')->nullable()->constrained('ride_places')->nullOnDelete();
            $table->string('place_label', 255);                     // where: the other end of the trip
            $table->decimal('place_lat', 10, 7)->nullable();
            $table->decimal('place_lng', 10, 7)->nullable();
            $table->timestamp('pickup_at')->nullable();             // null = as soon as possible
            $table->unsignedTinyInteger('passengers')->default(1);
            $table->unsignedTinyInteger('bags')->default(0);
            $table->string('flight', 20)->nullable();
            $table->string('notes', 300)->nullable();
            $table->decimal('distance_km', 8, 1)->nullable();
            $table->decimal('fare', 12, 2)->nullable();             // null = the hotel confirms the price
            $table->string('payment', 10)->default('room');         // room (on the bill), cash, card
            $table->string('status', 12)->default('requested');     // requested, assigned, on_the_way, arrived, on_trip, completed, cancelled
            $table->string('via', 8)->default('app');               // app, web, desk
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('eta_minutes')->nullable(); // driver to pickup, or to the drop-off once on the trip
            $table->foreignId('folio_charge_id')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'pickup_at']);
        });

        // Who drives, and who dispatches (existing installs: the same as a fresh seed).
        if (Schema::hasTable('permissions')) {
            foreach (['Drive guests', 'Manage transport'] as $name) {
                DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['title' => 'Transport', 'updated_at' => now(), 'created_at' => now()]);
            }
            $perm = fn ($n) => DB::table('permissions')->where('name', $n)->where('guard_name', 'web')->value('id');
            if (! DB::table('roles')->where('name', 'Driver')->exists()) {
                DB::table('roles')->insert(['name' => 'Driver', 'guard_name' => 'web', 'badge' => 'badge bg-info', 'created_at' => now(), 'updated_at' => now()]);
            }
            $grants = ['Driver' => ['dashboard', 'Drive guests'], 'General Manager' => ['Manage transport'], 'Front Desk' => ['Manage transport']];
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
        Schema::dropIfExists('rides');
        Schema::dropIfExists('ride_places');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }
};
