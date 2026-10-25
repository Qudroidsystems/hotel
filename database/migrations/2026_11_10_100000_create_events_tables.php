<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events & halls: halls and their rates, packages (per-person catering), and event bookings from first
 * enquiry to the day itself, with their extras, payments, and billing to a company account.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('halls')) {
            Schema::create('halls', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->unsignedSmallInteger('capacity_theatre')->nullable();
                $table->unsignedSmallInteger('capacity_banquet')->nullable();
                $table->unsignedSmallInteger('capacity_classroom')->nullable();
                $table->unsignedSmallInteger('capacity_boardroom')->nullable();
                $table->decimal('hourly_rate', 12, 2)->default(0);
                $table->decimal('half_day_rate', 12, 2)->default(0);
                $table->decimal('full_day_rate', 12, 2)->default(0);
                $table->string('description', 500)->nullable();
                $table->string('color', 9)->default('#0f766e');
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('event_packages')) {
            Schema::create('event_packages', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->decimal('price_per_person', 12, 2);
                $table->string('description', 500)->nullable();   // what is included
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table) {
                $table->id();
                $table->string('number', 20)->nullable()->unique();         // EV-000001
                $table->string('title', 150);
                $table->string('event_type', 20)->default('meeting');
                $table->string('status', 12)->default('enquiry')->index();  // enquiry | tentative | confirmed | completed | cancelled
                $table->foreignId('hall_id')->constrained()->restrictOnDelete();
                $table->dateTime('starts_at')->index();
                $table->dateTime('ends_at');
                $table->string('setup', 15)->nullable();                     // theatre | banquet | classroom | boardroom | cocktail
                $table->unsignedSmallInteger('guests_expected')->default(0);
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                $table->string('organiser_name', 120);
                $table->string('organiser_phone', 40)->nullable();
                $table->string('organiser_email', 150)->nullable();
                $table->foreignId('package_id')->nullable()->constrained('event_packages')->nullOnDelete();
                $table->decimal('package_price', 12, 2)->default(0);        // per person, as agreed
                $table->string('hall_rate_type', 10)->default('full_day');   // hourly | half_day | full_day | custom
                $table->decimal('hall_charge', 12, 2)->default(0);
                $table->decimal('discount', 12, 2)->default(0);
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->decimal('deposit_due', 14, 2)->default(0);
                $table->text('requirements')->nullable();                   // what the client asked for (on the event sheet)
                $table->string('internal_notes', 1000)->nullable();
                $table->string('cancel_reason', 255)->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['hall_id', 'starts_at']);
            });
        }

        if (! Schema::hasTable('event_items')) {
            Schema::create('event_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 12)->default('other');               // catering | equipment | service | other
                $table->string('description', 150);
                $table->decimal('quantity', 10, 2)->default(1);
                $table->decimal('unit_price', 12, 2);
                $table->decimal('amount', 14, 2);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('event_payments')) {
            Schema::create('event_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained()->restrictOnDelete();
                $table->string('number', 20)->nullable()->unique();         // EP-000001
                $table->string('kind', 10)->default('payment');             // payment | refund
                $table->string('method', 20);
                $table->decimal('amount', 14, 2);
                $table->string('reference', 100)->nullable();
                $table->foreignId('bank_account_id')->nullable()->constrained('hotel_bank_accounts')->nullOnDelete();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('received_at')->index();
                $table->dateTime('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('void_reason', 255)->nullable();
                $table->timestamps();
            });
        }

        Schema::table('company_ledger_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('company_ledger_entries', 'event_id')) {
                $table->foreignId('event_id')->nullable()->after('payment_id')->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_ledger_entries', function (Blueprint $table) {
            if (Schema::hasColumn('company_ledger_entries', 'event_id')) {
                $table->dropConstrainedForeignId('event_id');
            }
        });
        Schema::dropIfExists('event_payments');
        Schema::dropIfExists('event_items');
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_packages');
        Schema::dropIfExists('halls');
    }
};
