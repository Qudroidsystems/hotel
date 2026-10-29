<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Two-step sign-in with an authenticator app (spec §18: multi-factor authentication for sensitive administrative access). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');            // encrypted
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');   // encrypted, hashed codes
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');   // stops a code being used twice
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']);
        });
    }
};
