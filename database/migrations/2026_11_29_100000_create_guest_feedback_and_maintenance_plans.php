<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest feedback after a stay (one survey per booking, low scores followed up
 * by a manager) and preventive maintenance plans (servicing that repeats:
 * a work order is opened a few days before each due date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('overall');                 // 1–5 stars
            $table->json('ratings')->nullable();                    // {room: 4, cleanliness: 5, staff: 5, food: 3, value: 4}
            $table->unsignedTinyInteger('recommend')->nullable();   // 0–10 "would you recommend us"
            $table->text('comment')->nullable();
            $table->boolean('contact_ok')->default(false);          // the guest is happy to be called back
            $table->boolean('may_quote')->default(false);           // the comment may be shown on the website
            $table->string('source', 10)->default('web');          // web | app | desk
            $table->string('status', 20)->default('new')->index(); // new | follow_up | resolved
            $table->text('follow_up_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('website_item_id')->nullable();       // shown on the website as a review
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('feedback_invited_at')->nullable();
        });

        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('title', 150);
            $table->string('category', 20);
            $table->string('scope', 10)->default('one');            // one (a room or a place) | rooms (every room)
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 120)->nullable();
            $table->text('description')->nullable();               // the checklist for the technician
            $table->unsignedSmallInteger('every')->default(1);
            $table->string('unit', 10)->default('month');           // day | week | month
            $table->date('next_due_on');
            $table->unsignedSmallInteger('lead_days')->default(3);  // open the work order this many days early
            $table->string('priority', 10)->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->date('last_done_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['active', 'next_due_on']);
        });

        Schema::table('maintenance_orders', function (Blueprint $table) {
            $table->foreignId('maintenance_plan_id')->nullable()->after('housekeeping_task_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_plan_id');
        });
        Schema::dropIfExists('maintenance_plans');
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('feedback_invited_at');
        });
        Schema::dropIfExists('guest_feedback');
    }
};
