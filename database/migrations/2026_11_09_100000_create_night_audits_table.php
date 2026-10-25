<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per closed business day: who closed it, when, and the day's report as it stood. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('night_audits')) {
            Schema::create('night_audits', function (Blueprint $table) {
                $table->id();
                $table->date('business_date')->unique();
                $table->string('status', 12)->default('closed');
                $table->json('report');
                $table->json('actions')->nullable();                 // no-shows marked, nights posted
                $table->string('notes', 1000)->nullable();
                $table->boolean('automatic')->default(false);
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('closed_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('night_audits');
    }
};
