<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest sign-in for the guest app: a one-time code to the e-mail or phone the
 * guest booked with, then a long-lived app session. No password. A guest is
 * their e-mail or phone: every booking made with it, at every hotel, is theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('contact', 150)->index();          // lower-case e-mail or +234… phone
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('guest_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('contact', 150)->index();
            $table->string('device', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_sessions');
        Schema::dropIfExists('guest_login_codes');
    }
};
