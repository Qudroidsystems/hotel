<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company accounts (city ledger): companies whose staff stay or hold events and are billed later. A guest's
 * bill (or part of it) is moved to the company's account at check-out; the company pays against monthly
 * statements. The ledger is append-only: mistakes are voided with a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->id();
                $table->string('account_number', 20)->nullable()->unique();   // CA-0001
                $table->string('name', 150)->unique();
                $table->string('contact_name', 120)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('address', 500)->nullable();
                $table->string('tax_number', 40)->nullable();
                $table->decimal('credit_limit', 14, 2)->default(0);             // 0 = no limit
                $table->unsignedSmallInteger('payment_terms_days')->default(30);
                $table->string('notes', 1000)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('company_ledger_entries')) {
            Schema::create('company_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->string('type', 12)->index();                           // invoice | payment | credit | adjustment
                $table->string('number', 20)->nullable()->unique();            // CI-000001 / CP-000001
                $table->date('entry_date')->index();
                $table->date('due_date')->nullable();
                $table->string('description', 255);
                $table->decimal('amount', 14, 2);                              // + owed by the company, − paid / credited
                $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete(); // the folio payment that moved the bill
                $table->string('method', 20)->nullable();
                $table->string('reference', 100)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('void_reason', 255)->nullable();
                $table->timestamps();
                $table->index(['company_id', 'entry_date']);
            });
        }

        foreach (['reservations' => 'rate_plan_id', 'guests' => null, 'rate_plans' => 'code'] as $t => $after) {
            Schema::table($t, function (Blueprint $table) use ($t, $after) {
                if (! Schema::hasColumn($t, 'company_id')) {
                    $col = $table->foreignId('company_id')->nullable();
                    if ($after) {
                        $col->after($after);
                    }
                    $col->constrained()->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['reservations', 'guests', 'rate_plans'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (Schema::hasColumn($t, 'company_id')) {
                    $table->dropConstrainedForeignId('company_id');
                }
            });
        }
        Schema::dropIfExists('company_ledger_entries');
        Schema::dropIfExists('companies');
    }
};
