<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Channex is no longer used: drop its room/rate links and rename the permissions to Room Calendars. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('channel_rate_plans');
        Schema::dropIfExists('channel_room_types');

        if (Schema::hasTable('permissions')) {
            foreach (['View channel manager' => 'View room calendars', 'Manage channel manager' => 'Manage room calendars'] as $old => $new) {
                if (! DB::table('permissions')->where('name', $new)->exists()) {
                    DB::table('permissions')->where('name', $old)->update(['name' => $new]);
                }
            }
            app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
    }
};
