<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staff mobile app: in-app alerts (Laravel's notifications table), the
 * phones that receive push notifications, and each person's app settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });
        }

        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('push_token', 255)->unique();
            $table->string('platform', 20)->nullable();
            $table->string('device_name', 80)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // {push: bool, groups: {work: bool, ...}, quiet_from: "22:00", quiet_to: "06:00"}
            $table->json('app_preferences')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('app_preferences'));
        Schema::dropIfExists('mobile_devices');
        Schema::dropIfExists('notifications');
    }
};
