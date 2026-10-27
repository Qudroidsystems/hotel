<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revenue tools: last-minute rate plans (booked no more than N days ahead),
 * and where a night's price came from (set by hand, or by the price
 * suggestions — which may only change their own prices).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_advance_days')->nullable()->after('min_advance_days');   // last minute
        });
        Schema::table('rate_days', function (Blueprint $table) {
            $table->string('source', 10)->nullable()->after('note');   // null = by hand, 'suggested' = price suggestions
        });
    }

    public function down(): void
    {
        Schema::table('rate_days', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('rate_plans', fn (Blueprint $t) => $t->dropColumn('max_advance_days'));
    }
};
