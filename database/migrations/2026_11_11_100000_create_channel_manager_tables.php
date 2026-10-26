<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room calendars (iCal) for Airbnb, Vrbo and similar sites, the bookings they bring in, and a sync log.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Calendars (iCal) for one room each: ours to give the site, and the site's to read.
        if (! Schema::hasTable('ical_feeds')) {
            Schema::create('ical_feeds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_id')->constrained()->cascadeOnDelete();
                $table->string('name', 60);                                  // Airbnb, Vrbo ...
                $table->text('import_url')->nullable();
                $table->string('export_token', 48)->unique();
                $table->boolean('is_active')->default(true);
                $table->dateTime('last_synced_at')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Every booking a channel sent us, as received, and what became of it.
        if (! Schema::hasTable('channel_bookings')) {
            Schema::create('channel_bookings', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 20);                              // ical
                $table->string('external_id', 191);                          // feed:uid
                $table->string('revision_id', 64)->nullable();
                $table->string('ota_name', 60)->nullable();
                $table->string('ota_code', 100)->nullable();
                $table->string('status', 20);                                // new, modified, cancelled
                $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('ical_feed_id')->nullable()->constrained()->nullOnDelete();
                $table->json('payload')->nullable();
                $table->string('result', 20)->default('pending');            // imported, updated, cancelled, failed, skipped
                $table->text('error')->nullable();
                $table->boolean('overbooked')->default(false);
                $table->dateTime('received_at');
                $table->dateTime('processed_at')->nullable();
                $table->timestamps();
                $table->index(['provider', 'external_id']);
                $table->index('result');
            });
        }

        if (! Schema::hasTable('channel_sync_logs')) {
            Schema::create('channel_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 20);
                $table->string('action', 30);                                // availability, rates, bookings, ical
                $table->boolean('ok')->default(true);
                $table->unsignedInteger('changes')->default(0);
                $table->string('message', 500)->nullable();
                $table->dateTime('created_at');
                $table->index(['provider', 'action', 'created_at']);
            });
        }

        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'channel')) {
                $table->string('channel', 60)->nullable()->after('source');          // Booking.com, Airbnb ...
                $table->string('channel_reference', 100)->nullable()->after('channel'); // the site's own booking number
                $table->string('channel_booking_id', 191)->nullable()->after('channel_reference')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'channel')) {
                $table->dropIndex(['channel_booking_id']);
                $table->dropColumn(['channel', 'channel_reference', 'channel_booking_id']);
            }
        });
        Schema::dropIfExists('channel_sync_logs');
        Schema::dropIfExists('channel_bookings');
        Schema::dropIfExists('ical_feeds');
    }
};
