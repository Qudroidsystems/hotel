<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT control room (spec §11): equipment register, automated monitors and
 * their check history, alerts, planned maintenance and the IT service desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_assets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('type', 30)->index();                    // router, switch, access_point, server, …
            $table->string('ip_address', 64)->nullable();
            $table->string('mac_address', 32)->nullable();
            $table->string('location', 120)->nullable();
            $table->string('make_model', 120)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->string('firmware', 60)->nullable();
            $table->date('purchased_on')->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('it_monitors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 20)->default('network');     // network, application, infrastructure
            $table->string('type', 20);                             // http, tcp, ping, ssl, database, disk, backup, queue
            $table->string('target', 500)->nullable();              // URL, host or folder
            $table->unsignedInteger('port')->nullable();
            $table->foreignId('asset_id')->nullable()->constrained('it_assets')->nullOnDelete();
            $table->string('severity', 10)->default('high');        // alert severity when it goes down
            $table->unsignedSmallInteger('interval_minutes')->default(5);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('alert_after')->default(2);  // failed checks in a row before an alert
            $table->unsignedSmallInteger('expect_status')->nullable(); // http: exact status wanted (else any 2xx/3xx)
            $table->string('keyword', 120)->nullable();             // http: page must contain this
            $table->decimal('threshold', 10, 2)->nullable();        // ms, %, hours or days depending on type
            $table->boolean('is_active')->default(true);
            $table->string('status', 12)->default('unknown')->index(); // up, degraded, down, unknown
            $table->unsignedInteger('failures')->default(0);        // consecutive failed checks
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->string('last_message', 500)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('status_since')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('it_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('it_monitors')->cascadeOnDelete();
            $table->string('status', 12);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('checked_at');
            $table->index(['monitor_id', 'checked_at']);
        });

        Schema::create('it_maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('description', 2000)->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();
            $table->string('affects', 255)->nullable();             // what staff will notice
            $table->json('monitor_ids')->nullable();                // alerts held for these while it runs
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('it_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();     // IT-000123
            $table->string('title', 150);
            $table->text('description');
            $table->string('category', 20)->index();
            $table->string('priority', 10)->default('normal')->index(); // critical, high, normal, low
            $table->string('status', 15)->default('open')->index();  // open, in_progress, waiting, resolved, closed
            $table->string('department', 80)->nullable();
            $table->string('location', 120)->nullable();
            $table->foreignId('asset_id')->nullable()->constrained('it_assets')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolve_due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 2000)->nullable();
            $table->string('root_cause', 1000)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('it_ticket_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 3000);
            $table->string('status', 15)->nullable();               // set when the update changed the status
            $table->boolean('is_internal')->default(false);         // IT staff only
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('it_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();     // AL-000123
            $table->foreignId('monitor_id')->nullable()->constrained('it_monitors')->nullOnDelete();
            $table->string('severity', 10)->default('high')->index();
            $table->string('title', 200);
            $table->string('message', 1000)->nullable();
            $table->string('status', 15)->default('open')->index(); // open, acknowledged, resolved
            $table->timestamp('opened_at')->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 1000)->nullable();
            $table->boolean('auto_resolved')->default(false);
            $table->foreignId('ticket_id')->nullable()->constrained('it_tickets')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_alerts');
        Schema::dropIfExists('it_ticket_updates');
        Schema::dropIfExists('it_tickets');
        Schema::dropIfExists('it_maintenance_windows');
        Schema::dropIfExists('it_checks');
        Schema::dropIfExists('it_monitors');
        Schema::dropIfExists('it_assets');
    }
};
