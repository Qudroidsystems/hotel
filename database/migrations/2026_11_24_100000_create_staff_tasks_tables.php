<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department tasks (spec §12.1): jobs given to a person or a whole department,
 * with priority, a due time, photos or documents, escalation when overdue and,
 * where asked for, a supervisor's check before the task counts as done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('number', 20)->nullable()->index();             // TSK-000001
            $table->string('title', 160);
            $table->text('details')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 120)->nullable();
            $table->string('priority', 10)->default('normal');              // low, normal, high, urgent
            $table->timestamp('due_at')->nullable();
            $table->string('status', 16)->default('open');                  // open, in_progress, awaiting_check, done, cancelled
            $table->boolean('needs_check')->default(false);                  // a supervisor confirms it was done properly
            $table->json('attachments')->nullable();                         // [{path, name, type}]
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_note')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('escalation_level')->default(0);    // 0 none, 1 told the department, 2 told management
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });

        Schema::create('staff_task_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('staff_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16)->default('note');                    // note, status, assigned, escalated, returned
            $table->text('body');
            $table->string('attachment', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_task_updates');
        Schema::dropIfExists('staff_tasks');
    }
};
