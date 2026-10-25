<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rates & seasons: rate plans (bed & breakfast, corporate, promo codes…), seasons that raise or lower the
 * room types' nightly rates, and a per-date rate calendar (price, closed to online sale, minimum stay).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rate_plans')) {
            Schema::create('rate_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('code', 20)->unique();
                $table->string('description', 500)->nullable();
                $table->string('inclusions', 255)->nullable();          // "Breakfast for two"
                $table->decimal('discount_percent', 5, 2)->default(0);   // off the night's rate
                $table->decimal('nightly_extra', 12, 2)->default(0);     // added per room per night (e.g. breakfast)
                $table->json('fixed_prices')->nullable();                // {room_type_id: price} instead of the night's rate
                $table->unsignedSmallInteger('min_nights')->nullable();
                $table->unsignedSmallInteger('max_nights')->nullable();
                $table->unsignedSmallInteger('min_advance_days')->nullable(); // early bird
                $table->date('valid_from')->nullable();                  // arrival dates
                $table->date('valid_to')->nullable();
                $table->json('arrival_days')->nullable();                // [5, 6] = Fri, Sat; null = any day
                $table->json('room_type_ids')->nullable();               // null = every room type
                $table->string('promo_code', 30)->nullable()->unique();  // online: only with this code
                $table->boolean('online')->default(false);
                $table->boolean('front_desk')->default(true);
                $table->boolean('non_refundable')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rate_seasons')) {
            Schema::create('rate_seasons', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->date('starts_on')->index();
                $table->date('ends_on')->index();
                $table->decimal('change_percent', 6, 2)->default(0);     // +25 = 25% dearer, -10 = 10% cheaper
                $table->decimal('change_amount', 12, 2)->default(0);     // added per night (may be negative)
                $table->json('room_type_ids')->nullable();
                $table->unsignedSmallInteger('min_nights')->nullable();  // online bookings arriving in the season
                $table->smallInteger('priority')->default(0);            // when seasons overlap, the higher wins
                $table->string('color', 9)->default('#b7832f');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rate_days')) {
            Schema::create('rate_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
                $table->date('date');
                $table->decimal('price', 12, 2)->nullable();             // replaces the rate and season for that night
                $table->boolean('closed')->default(false);               // not sold online that night
                $table->unsignedSmallInteger('min_nights')->nullable();  // online arrivals that day
                $table->string('note', 150)->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['room_type_id', 'date']);
            });
        }

        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'rate_plan_id')) {
                $table->foreignId('rate_plan_id')->nullable()->after('source')->constrained('rate_plans')->nullOnDelete();
                $table->string('rate_plan_name', 100)->nullable()->after('rate_plan_id');
                $table->string('promo_code', 30)->nullable()->after('rate_plan_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'rate_plan_id')) {
                $table->dropConstrainedForeignId('rate_plan_id');
                $table->dropColumn(['rate_plan_name', 'promo_code']);
            }
        });
        Schema::dropIfExists('rate_days');
        Schema::dropIfExists('rate_seasons');
        Schema::dropIfExists('rate_plans');
    }
};
