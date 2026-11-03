<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest laundry: a price list (each item with the services it can have: wash & iron, dry clean, press only) and
 * the guests' laundry bags. A guest asks from the guest app or their booking page; staff collect and count the
 * bag (the count goes on the room bill), then mark it ready and returned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 80);
            $table->string('group', 40)->default('Clothes');
            $table->decimal('wash_price', 12, 2)->nullable();    // wash & iron; empty = not offered
            $table->decimal('dry_price', 12, 2)->nullable();     // dry clean
            $table->decimal('press_price', 12, 2)->nullable();   // press (iron) only
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('laundry_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('room_number', 20)->nullable();
            $table->string('guest_name', 120)->nullable();
            $table->json('lines');                                  // [{item_id, name, service, quantity, unit_price}]
            $table->json('asked')->nullable();                      // what the guest listed, kept when staff count differently
            $table->boolean('express')->default(false);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('express_fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('note', 300)->nullable();                // the guest's note ("no starch")
            $table->string('status', 20)->default('requested');     // requested, collected, ready, returned, declined, cancelled
            $table->string('via', 10)->default('app');              // app, web, desk
            $table->string('staff_note', 255)->nullable();          // shown to the guest (decline reason, "count differed …")
            $table->string('issue', 500)->nullable();               // damage / missing item, recorded by staff
            $table->foreignId('folio_charge_id')->nullable()->constrained('folio_charges')->nullOnDelete();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        if (Schema::hasTable('permissions')) {
            foreach (['Handle laundry', 'Manage laundry'] as $name) {
                DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['title' => 'Guest Services', 'updated_at' => now(), 'created_at' => now()]);
            }
            $perm = fn ($n) => DB::table('permissions')->where('name', $n)->where('guard_name', 'web')->value('id');
            $grants = [
                'General Manager' => ['Handle laundry', 'Manage laundry'], 'Housekeeping Supervisor' => ['Handle laundry', 'Manage laundry'],
                'Housekeeping' => ['Handle laundry'], 'Front Desk' => ['Handle laundry'],
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
        Schema::dropIfExists('laundry_orders');
        Schema::dropIfExists('laundry_items');
        DB::table('permissions')->whereIn('name', ['Handle laundry', 'Manage laundry'])->delete();
    }
};
