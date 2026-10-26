<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group loyalty: one member number is shared by the same guest's record at
 * each hotel of a group, so the number is unique per hotel, not system-wide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique(['loyalty_number']);
            $table->unique(['hotel_id', 'loyalty_number']);
            $table->index('loyalty_number');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique(['hotel_id', 'loyalty_number']);
            $table->dropIndex(['loyalty_number']);
            $table->unique(['loyalty_number']);
        });
    }
};
