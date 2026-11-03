<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuel & energy reports: diesel deliveries (into a generator's tank, and into Expenses), and a sample of the
 * hotel's power every 15 minutes (what was needed, grid or generator, estimated diesel burn), so the month can be
 * reported: grid hours and outages, generator hours, diesel delivered against what the generators should have
 * burned and what the tank readings say they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('energy_fuel_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('source_id')->nullable()->constrained('energy_sources')->nullOnDelete();   // the tank it went into
            $table->dateTime('delivered_at')->index();
            $table->decimal('litres', 9, 1);
            $table->decimal('price_per_litre', 10, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_name', 120)->nullable();
            $table->string('invoice_ref', 60)->nullable();
            $table->decimal('level_after', 9, 1)->nullable();      // the tank read after filling, if it was
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('energy_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->dateTime('at')->index();
            $table->decimal('kw', 9, 1);                            // power needed
            $table->boolean('grid_on');
            $table->foreignId('source_id')->nullable()->constrained('energy_sources')->nullOnDelete();  // the main generator running
            $table->decimal('litres_per_hour', 8, 2)->default(0);   // estimated diesel burn then
            $table->unsignedSmallInteger('rooms_in_use')->default(0);
            $table->decimal('waste_kw', 9, 1)->default(0);          // left on where not needed (open suggestions)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('energy_samples');
        Schema::dropIfExists('energy_fuel_deliveries');
    }
};
