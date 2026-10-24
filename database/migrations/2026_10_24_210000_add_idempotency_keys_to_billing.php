<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each payment / charge form carries a one-time key. If a weak connection makes
 * the browser send the same form twice (or the cashier presses Save again after
 * a timeout), the second copy is recognised and not recorded twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('id');
        });
        Schema::table('folio_charges', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('folio_charges', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
