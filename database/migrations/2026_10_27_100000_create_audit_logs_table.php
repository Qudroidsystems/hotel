<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail (spec §14): one append-only row per sensitive action — who,
 * when, what, the record it touched, old and new values and the reason.
 * Rows are never edited or deleted by the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 150)->nullable();           // snapshot, survives the account being deleted
            $table->string('user_roles', 255)->nullable();          // snapshot of the roles held at the time
            $table->string('module', 40)->index();                  // billing, pos, inventory, users, auth…
            $table->string('action', 80)->index();                  // billing.charge_voided
            $table->string('description', 500);
            $table->nullableMorphs('subject');                      // the record acted on
            $table->string('subject_label', 150)->nullable();       // F-000123, Room 204, Coke 50cl
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 500)->nullable();
            $table->boolean('flagged')->default(false)->index();    // worth a manager's look (failed scans, big voids…)
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
