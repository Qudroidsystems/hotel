<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Several hotels: each hotel lists its own bank accounts (two hotels may show the same group account). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_bank_accounts', function (Blueprint $table) {
            $table->unique(['hotel_id', 'bank_name', 'account_number']);
        });
        Schema::table('hotel_bank_accounts', function (Blueprint $table) {
            $table->dropUnique(['bank_name', 'account_number']);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_bank_accounts', function (Blueprint $table) {
            $table->unique(['bank_name', 'account_number']);
            $table->dropUnique(['hotel_id', 'bank_name', 'account_number']);
        });
    }
};
