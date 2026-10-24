<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff QR attendance (spec: HR attendance).
 *
 * Staff carry a badge with a QR code; a kiosk at the staff entrance scans it.
 * Kiosks work offline: each scan gets a random `uuid` on the device and is
 * sent when the network allows. The server accepts each uuid once.
 * Punches are only timestamps — whether a punch is "in" or "out", and the
 * day's lateness and hours, are worked out from all of the day's punches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('code', 10)->nullable()->unique();
            $table->string('color', 9)->default('#1e4a7e');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('code', 10)->unique();                   // MOR, AFT, NGT
            $table->time('start_time');
            $table->time('end_time');                                // earlier than start = ends next day
            $table->unsignedSmallInteger('grace_minutes')->default(10);       // late after start + grace
            $table->unsignedSmallInteger('early_grace_minutes')->default(10); // leaving this much early is fine
            $table->unsignedSmallInteger('break_minutes')->default(0);        // unpaid break taken off scheduled hours
            $table->string('color', 9)->default('#0d9488');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('staff_no', 20)->unique();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();   // usual shift
            $table->json('work_days')->nullable();                   // ISO weekdays 1=Mon … 7=Sun
            $table->string('job_title', 80)->nullable();
            $table->date('employment_start')->nullable();
            $table->boolean('tracks_attendance')->default(true);
            $table->string('badge_token', 64)->unique();             // what the badge QR carries
            $table->timestamp('badge_issued_at')->nullable();
            $table->timestamps();
        });

        // Where staff clock in (a tablet/phone running the kiosk page).
        Schema::create('attendance_points', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('code', 10)->unique();
            $table->string('location', 120)->nullable();
            $table->boolean('take_photo')->default(true);            // snap a photo with each scan
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                          // made on the kiosk; the duplicate guard
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('point_id')->nullable()->constrained('attendance_points')->nullOnDelete();
            $table->timestamp('punched_at')->index();                // the kiosk's clock (or the time HR entered)
            $table->date('work_date')->index();                      // the shift day it belongs to (night shifts!)
            $table->string('direction', 3)->nullable();              // in / out — worked out from the day's punches
            $table->string('source', 10)->default('kiosk');          // kiosk, manual
            $table->string('photo_path', 255)->nullable();
            $table->string('device_id', 20)->nullable();
            $table->boolean('was_offline')->default(false);
            $table->boolean('ignored')->default(false);              // a repeat scan seconds after the last one
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'work_date']);
        });

        // One row per person per day they had a shift or a punch (summary, recalculated).
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expected_start')->nullable();
            $table->timestamp('expected_end')->nullable();
            $table->timestamp('first_in')->nullable();
            $table->timestamp('last_out')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->boolean('is_in')->default(false);                // clocked in, not yet out
            $table->string('status', 12)->index();                   // present, late, absent, leave, off, missing_out
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
        });

        // Changes to the usual pattern for a given day (a different shift, or a day off).
        Schema::create('staff_rota', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete(); // null = day off
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
        });

        Schema::create('staff_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                              // annual, sick, maternity, compassionate, unpaid, other
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 255)->nullable();
            $table->string('status', 12)->default('approved');       // approved, cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_leaves');
        Schema::dropIfExists('staff_rota');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_points');
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('departments');
    }
};
