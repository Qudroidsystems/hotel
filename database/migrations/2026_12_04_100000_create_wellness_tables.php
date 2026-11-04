<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spa, gym and pool: the places (each with hours and how many people at once), the spa's treatments and
 * therapists, and bookings — by in-house guests (charged to the room), by visitors (paid online or at the
 * door) and at the desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wellness_venues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 80);
            $table->string('kind', 12)->default('spa');                    // spa (treatments), gym, pool, other (sessions)
            $table->string('description', 500)->nullable();
            $table->string('opens', 5)->default('07:00');
            $table->string('closes', 5)->default('21:00');
            $table->unsignedSmallInteger('capacity')->default(10);         // people at once (sessions); treatment rooms (spa without therapists)
            $table->unsignedSmallInteger('session_minutes')->default(60);   // gym / pool: one booking lasts this long
            $table->decimal('guest_price', 12, 2)->default(0);              // per person per session for in-house guests (0 = free)
            $table->boolean('visitors')->default(true);                     // people not staying can book (and pay online)
            $table->decimal('visitor_price', 12, 2)->nullable();            // per person per session for visitors (day pass); empty = the guest price
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wellness_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('venue_id')->constrained('wellness_venues')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('minutes')->default(60);
            $table->decimal('price', 12, 2);                                 // in-house guests
            $table->decimal('visitor_price', 12, 2)->nullable();            // visitors; empty = same price
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wellness_therapists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('venue_id')->constrained('wellness_venues')->cascadeOnDelete();
            $table->string('name', 80);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('wellness_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('venue_id')->constrained('wellness_venues');
            $table->foreignId('service_id')->nullable()->constrained('wellness_services')->nullOnDelete();
            $table->foreignId('therapist_id')->nullable()->constrained('wellness_therapists')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->unsignedSmallInteger('people')->default(1);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->decimal('total', 12, 2)->default(0);
            $table->string('payment', 8)->default('room');                   // room, online, desk, free
            $table->string('status', 12)->default('booked');                 // pending (paying online), booked, arrived, completed, no_show, cancelled, expired
            $table->string('via', 6)->default('desk');                       // app, web, desk
            $table->string('note', 300)->nullable();
            $table->string('staff_note', 255)->nullable();
            $table->string('token', 48)->nullable()->unique();               // the booker's page (visitors)
            $table->string('gateway_reference', 60)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('folio_charge_id')->nullable()->constrained('folio_charges')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['venue_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        if (Schema::hasTable('permissions')) {
            foreach (['Manage wellness bookings', 'Set up wellness'] as $name) {
                DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['title' => 'Guest Services', 'updated_at' => now(), 'created_at' => now()]);
            }
            $perm = fn ($n) => DB::table('permissions')->where('name', $n)->where('guard_name', 'web')->value('id');
            $grants = ['General Manager' => ['Manage wellness bookings', 'Set up wellness'], 'Front Desk' => ['Manage wellness bookings']];
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
        Schema::dropIfExists('wellness_bookings');
        Schema::dropIfExists('wellness_therapists');
        Schema::dropIfExists('wellness_services');
        Schema::dropIfExists('wellness_venues');
        DB::table('permissions')->whereIn('name', ['Manage wellness bookings', 'Set up wellness'])->delete();
    }
};
