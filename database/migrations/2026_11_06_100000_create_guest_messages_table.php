<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every SMS / WhatsApp message to or from a guest, with what happened to it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 3)->default('out');   // out | in
            $table->string('channel', 10);                     // sms | whatsapp
            $table->string('to', 40)->nullable();
            $table->string('from', 40)->nullable();
            $table->text('body');
            $table->string('template', 40)->nullable();        // booking_confirmed … or manual
            $table->string('status', 12);                      // sent | delivered | read | failed | skipped | received
            $table->string('provider_id', 64)->nullable()->unique();
            $table->string('error', 255)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('read_at')->nullable();          // inbound: seen by staff
            $table->timestamps();
            $table->index(['reservation_id', 'template']);
            $table->index(['direction', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_messages');
    }
};
