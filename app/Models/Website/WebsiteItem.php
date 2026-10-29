<?php

namespace App\Models\Website;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One piece of the public website's content. Which fields a kind uses (and what they are called on the
 * admin form) is in KINDS, so a new kind of content needs no new table.
 */
class WebsiteItem extends Model
{
    use BelongsToHotel;

    /**
     * kind => [singular, plural, icon, intro, fields (field => label), detail page?]
     * Fields: subtitle, category, summary, body, image, icon, meta, link, promo_code, dates, rating, published_at.
     */
    public const KINDS = [
        'slide' => ['Hero slide', 'Hero slides', 'ri-slideshow-3-line', 'The big pictures at the top of the home page, one after another. Use wide photos (at least 1920 × 1080).', [
            'subtitle' => 'Small line above the heading', 'summary' => 'Text under the heading', 'image' => 'Photo', 'link' => 'Button',
        ], false],
        'amenity' => ['Highlight', 'Highlights', 'ri-sparkling-2-line', 'Short points shown with an icon on the home page and in the guest app: free Wi-Fi, airport pick-up, pool …', [
            'icon' => 'Icon', 'summary' => 'One line about it',
        ], false],
        'dining' => ['Restaurant or bar', 'Dining', 'ri-restaurant-2-line', 'Your restaurants, bars and lounges on the Dining page.', [
            'subtitle' => 'Cuisine or style (e.g. "All-day dining · African & continental")', 'category' => 'Type (Restaurant, Bar, Lounge …)', 'summary' => 'Short description',
            'body' => 'Full description', 'image' => 'Photo', 'meta' => 'Opening hours', 'link' => 'Button (e.g. menu PDF)',
        ], true],
        'experience' => ['Experience', 'Experiences', 'ri-compass-3-line', 'Things to do at and around the hotel: spa, pool, gym, tours, kids club …', [
            'subtitle' => 'Short line', 'category' => 'Category (Wellness, Leisure, Tours …)', 'summary' => 'Short description', 'body' => 'Full description',
            'image' => 'Photo', 'meta' => 'Hours or price', 'icon' => 'Icon', 'link' => 'Button',
        ], true],
        'offer' => ['Offer', 'Special offers', 'ri-price-tag-3-line', 'Packages and deals. Give a promo code (made under Rates & Seasons) and the booking form opens with it filled in.', [
            'subtitle' => 'Short line', 'summary' => 'Short description', 'body' => 'Full details and terms', 'image' => 'Photo',
            'meta' => 'Price line (e.g. "From ₦85,000 a night" or "Save 20%")', 'promo_code' => 'Promo code', 'dates' => 'Shown between', 'link' => 'Button (instead of booking)',
        ], true],
        'gallery' => ['Photo', 'Gallery', 'ri-gallery-line', 'Photos on the Gallery page, sorted into categories guests can filter by.', [
            'image' => 'Photo', 'category' => 'Category (Rooms, Dining, Pool, Events …)', 'summary' => 'Caption',
        ], false],
        'post' => ['Journal post', 'Journal', 'ri-quill-pen-line', 'News and stories: events coming up, new menus, things to do in town.', [
            'category' => 'Topic', 'summary' => 'Short introduction', 'body' => 'The post', 'image' => 'Photo', 'meta' => 'Written by', 'published_at' => 'Publish date',
        ], true],
        'review' => ['Guest review', 'Guest reviews', 'ri-chat-quote-line', 'What guests say, shown on the home page.', [
            'summary' => 'What they said', 'meta' => 'Where from (e.g. "Abuja · Google review")', 'rating' => 'Stars',
        ], false],
        'faq' => ['Question', 'Questions & answers', 'ri-question-answer-line', 'Common questions, shown on the Contact page.', [
            'body' => 'Answer', 'category' => 'Topic',
        ], false],
        'page' => ['Page', 'Extra pages', 'ri-file-text-line', 'Pages of your own (e.g. Careers, Sustainability, Airport transfer), linked in the footer.', [
            'subtitle' => 'Line under the title', 'summary' => 'Short description (for search engines)', 'body' => 'Page text', 'image' => 'Photo',
        ], true],
    ];

    /** Icons offered for highlights and experiences (Remix Icon names). */
    public const ICONS = [
        'ri-wifi-line' => 'Wi-Fi', 'ri-plane-line' => 'Airport', 'ri-car-line' => 'Car', 'ri-parking-box-line' => 'Parking', 'ri-restaurant-line' => 'Restaurant',
        'ri-goblet-line' => 'Bar', 'ri-cup-line' => 'Coffee', 'ri-cake-3-line' => 'Breakfast', 'ri-water-flash-line' => 'Pool', 'ri-run-line' => 'Gym', 'ri-heart-pulse-line' => 'Wellness',
        'ri-leaf-line' => 'Spa', 'ri-sun-line' => 'Sun', 'ri-shield-check-line' => 'Security', 'ri-customer-service-2-line' => '24-hour desk', 'ri-t-shirt-air-line' => 'Laundry',
        'ri-briefcase-4-line' => 'Business', 'ri-presentation-line' => 'Meetings', 'ri-gift-line' => 'Gift', 'ri-team-line' => 'Family', 'ri-bear-smile-line' => 'Kids',
        'ri-map-pin-line' => 'Location', 'ri-building-line' => 'Building', 'ri-music-2-line' => 'Music', 'ri-flashlight-line' => 'Power', 'ri-key-2-line' => 'Key',
        'ri-star-line' => 'Star', 'ri-vip-crown-line' => 'Crown', 'ri-tv-2-line' => 'TV', 'ri-temp-cold-line' => 'Air conditioning',
    ];

    protected $fillable = [
        'kind', 'title', 'slug', 'subtitle', 'summary', 'body', 'image', 'icon', 'category', 'meta', 'link_label', 'link_url', 'promo_code',
        'starts_on', 'ends_on', 'rating', 'is_featured', 'is_active', 'sort_order', 'published_at', 'created_by',
    ];

    protected $casts = [
        'starts_on' => 'date', 'ends_on' => 'date', 'published_at' => 'datetime',
        'is_featured' => 'boolean', 'is_active' => 'boolean', 'rating' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            if (self::hasPage($item->kind) && blank($item->slug)) {
                $base = Str::slug($item->title) ?: $item->kind;
                $slug = $base;
                for ($i = 2; static::where('kind', $item->kind)->where('slug', $slug)->whereKeyNot($item->id)->exists(); $i++) {
                    $slug = "{$base}-{$i}";
                }
                $item->slug = $slug;
            }
        });
        static::deleted(function (self $item) {
            \App\Support\Website::deleteUpload($item->image);
        });
    }

    public static function hasPage(string $kind): bool
    {
        return (bool) (self::KINDS[$kind][5] ?? false);
    }

    public static function uses(string $kind, string $field): bool
    {
        return $field === 'title' || array_key_exists($field, self::KINDS[$kind][4] ?? []);
    }

    public function scopeOfKind(Builder $q, string $kind): Builder
    {
        return $q->where('kind', $kind);
    }

    /** Switched on, inside its dates, and (journal posts) published. */
    public function scopeLive(Builder $q): Builder
    {
        $today = today()->toDateString();

        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderByDesc('is_featured')->orderBy('sort_order')->orderByDesc('published_at')->orderBy('id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        // Under public/ (uploads/website/…, or a picture that ships with the theme for the demo content).
        return asset($this->image);
    }

    /** The item's own page on the website, for kinds that have one. */
    public function getUrlAttribute(): ?string
    {
        return match ($this->kind) {
            'dining'     => route('site.dining.show', $this->slug),
            'experience' => route('site.experiences.show', $this->slug),
            'offer'      => route('site.offers.show', $this->slug),
            'post'       => route('site.journal.show', $this->slug),
            'page'       => route('site.page', $this->slug),
            default      => null,
        };
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind][0] ?? ucfirst($this->kind);
    }

    /** Paragraphs of the body text, for pages (blank lines split them). */
    public function getParagraphsAttribute(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $this->body))));
    }

    public function isScheduledOut(): bool
    {
        return ($this->starts_on && $this->starts_on->isFuture()) || ($this->ends_on && $this->ends_on->lt(today()))
            || ($this->published_at && $this->published_at->isFuture());
    }
}
