<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group booking site commission: bookings a guest makes through the group site (or the guest app's hotel
 * list) carry the hotel's commission rate; each month every hotel gets a statement of what it owes the group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            if (! Schema::hasColumn('hotels', 'commission_percent')) {
                $table->decimal('commission_percent', 5, 2)->nullable()->after('sort_order');   // null: the group's usual rate
            }
        });
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'via_group')) {
                $table->boolean('via_group')->default(false)->after('channel_booking_id');
                $table->decimal('commission_percent', 5, 2)->nullable()->after('via_group');
                $table->decimal('commission_amount', 12, 2)->default(0)->after('commission_percent');
            }
        });
        if (! Schema::hasTable('commission_payments')) {
            Schema::create('commission_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->string('period', 7);                       // 2026-11
                $table->decimal('amount', 12, 2);
                $table->date('received_on');
                $table->string('reference', 100)->nullable();
                $table->string('note', 255)->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['hotel_id', 'period']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payments');
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn(['via_group', 'commission_percent', 'commission_amount']));
        Schema::table('hotels', fn (Blueprint $t) => $t->dropColumn('commission_percent'));
    }
};
