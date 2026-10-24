<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hotel's own bank accounts: printed on invoices so guests know where to
 * pay, and picked when a transfer is received so each account can be
 * reconciled against its bank statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name', 100);
            $table->string('account_name', 150);
            $table->string('account_number', 30);
            $table->string('branch', 100)->nullable();
            $table->string('sort_code', 30)->nullable();               // sort code / SWIFT / routing, if needed
            $table->string('currency', 3)->default('NGN');
            $table->string('purpose', 100)->nullable();               // e.g. "Room payments", "Bar & restaurant"
            $table->boolean('show_on_invoice')->default(true);
            $table->boolean('accepts_transfers')->default(true);      // offered when recording a transfer
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['bank_name', 'account_number']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('reference')->constrained('hotel_bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
        Schema::dropIfExists('hotel_bank_accounts');
    }
};
