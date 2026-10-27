<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Guest ordering: food and drinks ordered from the guest app or the QR card
 * in the room. An order waits for the outlet to accept it; accepting it rings
 * it up as a POS sale charged to the room, so stock and the bill follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->boolean('guest_orders')->default(false)->after('allow_room_charge');   // takes orders from guests' rooms
            $table->string('guest_hours', 11)->nullable()->after('guest_orders');            // "07:00-23:00"; empty = always
            $table->unsignedSmallInteger('delivery_minutes')->nullable()->after('guest_hours');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('guest_code', 16)->nullable()->unique()->after('status');        // the room's QR card: /room/{code}
        });
        foreach (DB::table('rooms')->whereNull('guest_code')->pluck('id') as $id) {
            DB::table('rooms')->where('id', $id)->update(['guest_code' => Str::lower(Str::random(12))]);
        }

        Schema::create('guest_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->uuid('uuid')->unique();                                   // becomes the POS sale's uuid
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('pos_outlets');
            $table->string('room_number', 20)->nullable();
            $table->string('guest_name', 120)->nullable();
            $table->json('lines');                                            // [{item_id, name, charge_type, quantity, unit_price, note}]
            $table->string('note', 300)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('status', 20)->default('placed');                  // placed, accepted, on_the_way, delivered, declined, cancelled
            $table->string('via', 10)->default('app');                        // app, web, room (QR card)
            $table->string('staff_note', 255)->nullable();
            $table->foreignId('pos_order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_orders');
        Schema::table('rooms', function (Blueprint $t) {
            $t->dropUnique(['guest_code']);
            $t->dropColumn('guest_code');
        });
        Schema::table('pos_outlets', fn (Blueprint $t) => $t->dropColumn(['guest_orders', 'guest_hours', 'delivery_minutes']));
    }
};
