<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internet & guest Wi-Fi (IT control room): the hotel's internet lines (Starlink
 * dishes, fibre, 4G), their readings over time, Wi-Fi speed plans and the Wi-Fi
 * codes given to guests for their stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('provider', 20)->default('starlink');            // starlink, fibre, lte, other
            $table->string('role', 12)->default('shared');                  // shared, guests, staff, backup
            $table->string('identifier', 120)->nullable();                  // dish serial / account / circuit number
            $table->string('address', 120)->nullable();                     // dish address, e.g. 192.168.100.1
            $table->string('router_port', 60)->nullable();                  // the gateway port it plugs into, e.g. ether1
            $table->unsignedTinyInteger('weight')->default(1);              // share of traffic when balancing
            $table->decimal('data_cap_gb', 10, 2)->nullable();              // monthly allowance on the plan, if any
            $table->unsignedTinyInteger('billing_day')->default(1);         // the day the monthly allowance resets
            $table->decimal('monthly_cost', 12, 2)->nullable();
            $table->string('location', 120)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status', 10)->default('unknown')->index();      // online, degraded, offline, unknown
            $table->unsignedTinyInteger('signal')->nullable();              // 0–100
            $table->decimal('down_mbps', 8, 2)->nullable();
            $table->decimal('up_mbps', 8, 2)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('packet_loss', 5, 2)->nullable();               // %
            $table->decimal('obstruction_pct', 5, 2)->nullable();           // Starlink: sky view blocked %
            $table->decimal('used_gb_month', 12, 3)->default(0);
            $table->string('last_message', 500)->nullable();
            $table->timestamp('status_since')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('alert_id')->nullable()->constrained('it_alerts')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('network_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('link_id')->constrained('network_links')->cascadeOnDelete();
            $table->dateTime('sampled_at');
            $table->string('status', 10);
            $table->unsignedTinyInteger('signal')->nullable();
            $table->decimal('down_mbps', 8, 2)->nullable();
            $table->decimal('up_mbps', 8, 2)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('packet_loss', 5, 2)->nullable();
            $table->decimal('obstruction_pct', 5, 2)->nullable();
            $table->decimal('used_mb', 12, 2)->default(0);                  // data moved since the previous reading
            $table->index(['link_id', 'sampled_at']);
        });

        Schema::create('network_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->decimal('down_mbps', 8, 2);
            $table->decimal('up_mbps', 8, 2);
            $table->unsignedTinyInteger('devices')->default(3);
            $table->unsignedInteger('data_cap_mb')->nullable();             // per day; empty = unlimited
            $table->decimal('price', 12, 2)->default(0);                    // per day, charged to the room bill
            $table->boolean('is_default')->default(false);                  // given to every guest at check-in
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('network_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('code', 20)->index();
            $table->string('kind', 10)->default('stay');                    // stay, visitor, staff, event
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('network_plans')->nullOnDelete();
            $table->string('holder', 120)->nullable();                      // guest / visitor name
            $table->string('room_number', 20)->nullable();
            $table->unsignedTinyInteger('devices')->default(3);
            $table->decimal('down_mbps', 8, 2)->nullable();                 // copied from the plan when given
            $table->decimal('up_mbps', 8, 2)->nullable();
            $table->unsignedInteger('data_cap_mb')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('expires_at')->index();
            $table->string('status', 10)->default('active')->index();       // active, expired, revoked
            $table->decimal('used_mb', 12, 2)->default(0);
            $table->unsignedTinyInteger('devices_online')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('folio_charge_id')->nullable()->constrained('folio_charges')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamp('synced_at')->nullable();                     // last sent to the router
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_passes');
        Schema::dropIfExists('network_plans');
        Schema::dropIfExists('network_samples');
        Schema::dropIfExists('network_links');
    }
};
