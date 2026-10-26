<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-hotel: one system can run several hotels. Every hotel record (rooms, bookings, bills, stock …) now
 * carries the hotel it belongs to. An existing single-hotel system becomes "hotel #1" with all its data, and
 * works exactly as before.
 */
return new class extends Migration
{
    /** Tables whose rows belong to one hotel. Users, roles, devices and the like stay shared. */
    public const TABLES = [
        'attendance_days', 'attendance_points', 'attendance_punches', 'audit_logs', 'buildings', 'cash_shift_movements', 'cash_shifts',
        'channel_bookings', 'channel_sync_logs', 'companies', 'company_ledger_entries', 'departments', 'event_items', 'event_packages',
        'event_payments', 'events', 'expense_categories', 'expenses', 'floors', 'folio_charges', 'folios', 'guest_messages', 'guest_requests',
        'guests', 'halls', 'hotel_bank_accounts', 'housekeeping_tasks', 'ical_feeds', 'incident_updates', 'incidents', 'it_alerts', 'it_assets',
        'it_checks', 'it_maintenance_windows', 'it_monitors', 'it_ticket_updates', 'it_tickets', 'lost_items', 'loyalty_entries',
        'maintenance_orders', 'night_audits', 'online_payments', 'payments', 'pos_categories', 'pos_item_components', 'pos_items',
        'pos_order_items', 'pos_orders', 'pos_outlets', 'purchase_order_lines', 'purchase_orders', 'purchase_request_lines',
        'purchase_requests', 'rate_days', 'rate_plans', 'rate_seasons', 'reservation_events', 'reservation_rooms', 'reservations',
        'room_status_logs', 'room_type_photos', 'room_types', 'rooms', 'shifts', 'staff_leaves', 'staff_profiles', 'staff_rota',
        'stock_categories', 'stock_count_lines', 'stock_counts', 'stock_items', 'stock_levels', 'stock_locations', 'stock_movements',
        'stock_receipt_lines', 'stock_receipts', 'stock_transfer_lines', 'stock_transfers', 'supplier_invoices', 'supplier_payments',
        'suppliers', 'visitors',
    ];

    /** Names and codes that were unique in the whole system and are now unique per hotel. */
    private const UNIQUES = [
        'buildings' => ['code'], 'room_types' => ['code'], 'rooms' => ['number'], 'pos_outlets' => ['code'], 'pos_categories' => ['name'],
        'pos_items' => ['sku'], 'stock_locations' => ['code'], 'stock_categories' => ['name'], 'stock_items' => ['sku'],
        'departments' => ['name', 'code'], 'shifts' => ['code'], 'attendance_points' => ['code'], 'expense_categories' => ['name'],
        'rate_plans' => ['code', 'promo_code'], 'companies' => ['name'], 'night_audits' => ['business_date'], 'halls' => ['name'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('hotels')) {
            Schema::create('hotels', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 80)->unique();                 // the hotel's address on the booking site
                $table->string('city', 80)->nullable();
                $table->string('state', 80)->nullable();
                $table->string('country', 80)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('email', 150)->nullable();
                $table->text('description')->nullable();
                $table->string('logo', 255)->nullable();
                $table->string('cover_photo', 255)->nullable();
                $table->unsignedTinyInteger('stars')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_listed')->default(true);          // shown on the group booking site
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('hotel_user')) {
            Schema::create('hotel_user', function (Blueprint $table) {
                $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->primary(['hotel_id', 'user_id']);
            });
        }

        // The existing hotel becomes hotel #1.
        $first = DB::table('hotels')->orderBy('id')->value('id');
        if (! $first) {
            $saved = Schema::hasTable('hotel_settings') ? DB::table('hotel_settings')->where('key', 'name')->value('value') : null;
            $saved = $saved ? json_decode($saved, true) : null;
            $name = is_string($saved) && trim($saved) !== '' ? trim($saved) : (string) config('hotel.name', 'Hotel');
            $first = DB::table('hotels')->insertGetId([
                'name' => $name, 'slug' => Str::slug($name) ?: 'hotel', 'address' => config('hotel.booking.address'),
                'phone' => config('hotel.booking.contact_phone'), 'email' => config('hotel.booking.contact_email'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (self::TABLES as $t) {
            if (! Schema::hasTable($t) || Schema::hasColumn($t, 'hotel_id')) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('hotel_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
            DB::table($t)->whereNull('hotel_id')->update(['hotel_id' => $first]);
        }

        // Settings: a row with no hotel is shared by all hotels (the group's own settings); hotel #1 keeps the existing ones.
        if (Schema::hasTable('hotel_settings') && ! Schema::hasColumn('hotel_settings', 'hotel_id')) {
            Schema::table('hotel_settings', function (Blueprint $table) {
                $table->foreignId('hotel_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
            DB::table('hotel_settings')->update(['hotel_id' => $first]);
            Schema::table('hotel_settings', function (Blueprint $table) {
                $table->dropUnique(['key']);
                $table->unique(['hotel_id', 'key']);
            });
        }

        foreach (self::UNIQUES as $t => $cols) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            foreach ($cols as $col) {
                Schema::table($t, function (Blueprint $table) use ($col) {
                    $table->dropUnique([$col]);
                    $table->unique(['hotel_id', $col]);
                });
            }
        }

        // Everyone already on the system works at hotel #1.
        foreach (DB::table('users')->pluck('id') as $userId) {
            DB::table('hotel_user')->insertOrIgnore(['hotel_id' => $first, 'user_id' => $userId]);
        }
    }

    public function down(): void
    {
        foreach (self::UNIQUES as $t => $cols) {
            foreach ($cols as $col) {
                Schema::table($t, function (Blueprint $table) use ($col) {
                    $table->dropUnique(['hotel_id', $col]);
                    $table->unique([$col]);
                });
            }
        }
        if (Schema::hasColumn('hotel_settings', 'hotel_id')) {
            Schema::table('hotel_settings', function (Blueprint $table) {
                $table->dropUnique(['hotel_id', 'key']);
                $table->unique(['key']);
                $table->dropConstrainedForeignId('hotel_id');
            });
        }
        foreach (self::TABLES as $t) {
            if (Schema::hasColumn($t, 'hotel_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropConstrainedForeignId('hotel_id'));
            }
        }
        Schema::dropIfExists('hotel_user');
        Schema::dropIfExists('hotels');
    }
};
