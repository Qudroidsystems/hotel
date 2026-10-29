<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hotel's public website, managed from Website in the sidebar: the site's settings and texts,
 * its content (hero slides, dining, experiences, offers, gallery, journal posts, pages, reviews, FAQs),
 * messages sent from its forms, and newsletter sign-ups. Rooms, halls and events come from their own modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->unique();
            $table->json('data')->nullable();                                  // see App\Support\Website::FIELDS
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('website_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('kind', 20);                                        // slide, dining, experience, offer, gallery, post, page, review, faq, amenity
            $table->string('title', 160);
            $table->string('slug', 180)->nullable();
            $table->string('subtitle', 200)->nullable();
            $table->string('summary', 600)->nullable();
            $table->text('body')->nullable();
            $table->string('image', 255)->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('meta', 160)->nullable();                           // opening hours, price, author, guest's home town …
            $table->string('link_label', 60)->nullable();
            $table->string('link_url', 255)->nullable();
            $table->string('promo_code', 30)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'is_active', 'sort_order']);
            $table->unique(['hotel_id', 'kind', 'slug']);
        });

        Schema::create('website_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('kind', 16)->default('contact');                    // contact, event, dining
            $table->string('name', 120);
            $table->string('email', 150);
            $table->string('phone', 30)->nullable();
            $table->string('subject', 160)->nullable();
            $table->text('message');
            $table->json('details')->nullable();                               // event date, guests, time …
            $table->string('status', 12)->default('new');                     // new, replied, closed
            $table->timestamp('read_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('note')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('website_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->index();
            $table->string('email', 150);
            $table->string('name', 120)->nullable();
            $table->string('source', 20)->default('website');                 // website, app
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_subscribers');
        Schema::dropIfExists('website_messages');
        Schema::dropIfExists('website_items');
        Schema::dropIfExists('website_settings');
    }
};
