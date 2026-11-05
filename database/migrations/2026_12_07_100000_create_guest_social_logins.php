<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guests who sign in with Google or Apple. The e-mail the provider confirmed
 * is the guest's account (the same as signing in with a code), so this only
 * remembers which Google / Apple account goes with which e-mail: Apple may
 * leave the e-mail out after the first sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_social_logins', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->string('subject', 255);                  // the provider's id for the person
            $table->string('email', 150)->index();           // lower-case
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_social_logins');
    }
};
