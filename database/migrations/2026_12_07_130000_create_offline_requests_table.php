<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actions a phone or browser saved while it had no connection and sent later.
 * Each carries its own id: the answer is kept, so an action sent twice (the
 * connection dropped before the answer came back) happens only once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_requests', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();              // user id + the sender's id for the action
            $table->foreignId('user_id')->nullable()->index();
            $table->string('method', 8);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status')->default(0);   // 0 while it is running
            $table->longText('body')->nullable();
            $table->string('content_type', 100)->nullable();
            $table->timestamp('happened_at')->nullable();      // when it was pressed, if the sender said
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_requests');
    }
};
