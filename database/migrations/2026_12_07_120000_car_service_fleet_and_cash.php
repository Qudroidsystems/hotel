<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The car service, made watertight: each car's papers and servicing, a log of
 * checks, fuel and repairs, the kilometres of every trip, cash a driver took
 * from a guest until it is handed in, and no-shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedInteger('odometer_km')->nullable()->after('seats');
            $table->date('insurance_expires')->nullable()->after('odometer_km');
            $table->date('roadworthy_expires')->nullable()->after('insurance_expires');
            $table->unsignedInteger('service_every_km')->nullable()->after('roadworthy_expires');
            $table->unsignedInteger('last_service_km')->nullable()->after('service_every_km');
            $table->date('service_due_on')->nullable()->after('last_service_km');
            $table->timestamp('checked_at')->nullable()->after('service_due_on');   // the last pre-shift check
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedInteger('start_km')->nullable()->after('eta_minutes');
            $table->unsignedInteger('end_km')->nullable()->after('start_km');
            $table->boolean('no_show')->default(false)->after('cancel_reason');
            $table->decimal('cash_collected', 12, 2)->nullable()->after('folio_charge_id');
            $table->timestamp('cash_handed_at')->nullable()->after('cash_collected');
            $table->foreignId('cash_received_by')->nullable()->after('cash_handed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reminded_at')->nullable()->after('cancelled_at');
            $table->timestamp('driver_reminded_at')->nullable()->after('reminded_at');
        });

        Schema::create('vehicle_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12);                         // check, fuel, service, issue
            $table->unsignedInteger('odometer_km')->nullable();
            $table->decimal('litres', 8, 2)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->json('checklist')->nullable();              // {tyres: true, lights: false, …}
            $table->boolean('ok')->default(true);
            $table->string('notes', 500)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('offline_id', 64)->nullable()->unique();   // sent again after a lost connection: once only
            $table->timestamps();
            $table->index(['vehicle_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_logs');
        Schema::table('rides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_received_by');
            $table->dropColumn(['start_km', 'end_km', 'no_show', 'cash_collected', 'cash_handed_at', 'reminded_at', 'driver_reminded_at']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['odometer_km', 'insurance_expires', 'roadworthy_expires', 'service_every_km', 'last_service_km', 'service_due_on', 'checked_at']);
        });
    }
};
