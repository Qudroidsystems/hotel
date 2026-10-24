<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping (spec §3.3) and maintenance work orders (spec §3.4).
 *
 * A check-out turns the room to "Needs Cleaning", which opens a cleaning task;
 * the cleaner starts and finishes it, a supervisor inspects it, and only then
 * is the room available again. A work order can take a room out of sale until
 * the job is done, after which the room goes back through cleaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('checkout');           // checkout, stayover, deep, turndown, other
            $table->string('status', 20)->default('pending')->index(); // pending, in_progress, done (awaiting inspection), passed, cancelled
            $table->boolean('urgent')->default(false);                 // a guest is arriving today
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('finished_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inspection_note', 500)->nullable();
            $table->unsignedTinyInteger('failed_inspections')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'status']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('maintenance_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();        // WO-000123
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 120)->nullable();               // for places that aren't rooms: "Kitchen", "Generator house"
            $table->string('category', 20);                            // air_conditioning, plumbing, electrical, furniture, wifi, appliance, building, other
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal')->index(); // low, normal, high, urgent
            $table->string('status', 20)->default('open')->index();    // open, in_progress, on_hold, completed, cancelled
            $table->boolean('blocks_room')->default(false);            // room kept out of sale until done
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('housekeeping_task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('completion_note', 1000)->nullable();
            $table->decimal('cost', 12, 2)->nullable();                // parts + labour
            $table->string('hold_reason', 255)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->json('photos')->nullable();                        // [{path, stage: report|completion}]
            $table->timestamps();

            $table->index(['room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_orders');
        Schema::dropIfExists('housekeeping_tasks');
    }
};
