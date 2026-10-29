<?php

namespace App\Support;

use App\Models\Website\WebsiteSetting;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The public website's settings and texts (Website → Settings), one set per hotel.
 * Website::get('home.intro_title') reads a value, falling back to the default in FIELDS.
 */
class Website
{
    /** Colour schemes: key => [label, accent, accent dark, ink (dark background), paper (light background)] */
    public const THEMES = [
        'champagne' => ['Champagne gold on midnight', '#b8955a', '#8f6f3c', '#0e1726', '#f7f3ec'],
        'emerald'   => ['Gold on emerald', '#c7a46a', '#9a7b45', '#0d2a24', '#f4f1ea'],
        'bordeaux'  => ['Rose gold on bordeaux', '#c99a7f', '#a1735a', '#2a1016', '#f8f2ef'],
        'ocean'     => ['Sand on ocean blue', '#d1b07a', '#a8874f', '#0b2236', '#f3f5f4'],
        'graphite'  => ['Brass on graphite', '#b08d57', '#8a6b3a', '#1b1c1e', '#f5f4f1'],
    ];

    /**
     * group => [title, icon, fields]. field: type (text|textarea|bool|image|select|list|url), label, help, default.
     */
    public const FIELDS = [
        'general' => ['General', 'ri-settings-3-line', [
            'enabled'      => ['type' => 'bool', 'label' => 'Show the website at the hotel\'s main address', 'default' => true, 'help' => 'Off: the main address opens staff sign-in, as before. Online booking keeps working at /book.'],
            'tagline'      => ['type' => 'text', 'label' => 'Tagline', 'default' => 'A stay to remember', 'help' => 'Under the name in the footer and on the guest app home screen.'],
            'theme'        => ['type' => 'select', 'label' => 'Colours', 'default' => 'champagne'],
            'announcement' => ['type' => 'text', 'label' => 'Announcement bar', 'help' => 'One line across the top of every page, e.g. "Book direct and get breakfast free". Leave empty for none.'],
            'announcement_url' => ['type' => 'url', 'label' => 'Announcement link'],
            'notify_email' => ['type' => 'text', 'label' => 'Send website enquiries to', 'help' => 'An e-mail address. Every message from the website\'s forms is also kept under Website → Enquiries.'],
        ]],
        'home' => ['Home page', 'ri-home-4-line', [
            'home.intro_eyebrow' => ['type' => 'text', 'label' => 'Welcome: small line', 'default' => 'Welcome'],
            'home.intro_title'   => ['type' => 'text', 'label' => 'Welcome: heading', 'default' => 'Where every stay becomes a story'],
            'home.intro_text'    => ['type' => 'textarea', 'label' => 'Welcome: text', 'default' => 'Thoughtful rooms, warm service and a table worth staying in for. Whether you are here for business, a celebration or a few slow days, we look after the details so you don\'t have to.'],
            'home.intro_image'   => ['type' => 'image', 'label' => 'Welcome: photo'],
            'home.stats'         => ['type' => 'list', 'label' => 'Numbers to show', 'default' => ['24|Hour room service', '4|Places to eat & drink', '500|Guests in our halls'], 'help' => 'One per line, as number|words, e.g. 120|Rooms & suites. Leave empty to hide.'],
            'home.show_rooms'       => ['type' => 'bool', 'label' => 'Show rooms', 'default' => true],
            'home.show_offers'      => ['type' => 'bool', 'label' => 'Show offers', 'default' => true],
            'home.show_dining'      => ['type' => 'bool', 'label' => 'Show dining', 'default' => true],
            'home.show_experiences' => ['type' => 'bool', 'label' => 'Show experiences', 'default' => true],
            'home.show_events'      => ['type' => 'bool', 'label' => 'Show meetings & events', 'default' => true],
            'home.show_gallery'     => ['type' => 'bool', 'label' => 'Show gallery', 'default' => true],
            'home.show_reviews'     => ['type' => 'bool', 'label' => 'Show guest reviews', 'default' => true],
            'home.show_journal'     => ['type' => 'bool', 'label' => 'Show journal', 'default' => true],
            'home.show_newsletter'  => ['type' => 'bool', 'label' => 'Show newsletter sign-up', 'default' => true],
        ]],
        'pages' => ['Page texts', 'ri-layout-4-line', [
            'rooms.title'      => ['type' => 'text', 'label' => 'Rooms page: heading', 'default' => 'Rooms & Suites'],
            'rooms.text'       => ['type' => 'textarea', 'label' => 'Rooms page: text', 'default' => 'Calm, generous rooms with everything you need to rest, work and unwind.'],
            'rooms.image'      => ['type' => 'image', 'label' => 'Rooms page: photo'],
            'dining.title'     => ['type' => 'text', 'label' => 'Dining page: heading', 'default' => 'Dining'],
            'dining.text'      => ['type' => 'textarea', 'label' => 'Dining page: text', 'default' => 'From an early coffee to a late supper, our kitchens and bars are open to guests and visitors alike.'],
            'dining.image'     => ['type' => 'image', 'label' => 'Dining page: photo'],
            'dining.table_form' => ['type' => 'bool', 'label' => 'Dining page: guests can ask for a table', 'default' => true],
            'events.title'     => ['type' => 'text', 'label' => 'Meetings & events page: heading', 'default' => 'Meetings & Events'],
            'events.text'      => ['type' => 'textarea', 'label' => 'Meetings & events page: text', 'default' => 'Weddings, conferences, launches and birthdays: our halls, our kitchen and a team that has done it many times before.'],
            'events.image'     => ['type' => 'image', 'label' => 'Meetings & events page: photo'],
            'experiences.title' => ['type' => 'text', 'label' => 'Experiences page: heading', 'default' => 'Experiences'],
            'experiences.text' => ['type' => 'textarea', 'label' => 'Experiences page: text', 'default' => 'Moments beyond your room: wellness, leisure and the best of the city around us.'],
            'experiences.image' => ['type' => 'image', 'label' => 'Experiences page: photo'],
            'offers.title'     => ['type' => 'text', 'label' => 'Offers page: heading', 'default' => 'Special Offers'],
            'offers.text'      => ['type' => 'textarea', 'label' => 'Offers page: text', 'default' => 'Book direct for our best rates and packages you won\'t find anywhere else.'],
            'offers.image'     => ['type' => 'image', 'label' => 'Offers page: photo'],
            'gallery.title'    => ['type' => 'text', 'label' => 'Gallery page: heading', 'default' => 'Gallery'],
            'gallery.image'    => ['type' => 'image', 'label' => 'Gallery page: photo'],
            'journal.title'    => ['type' => 'text', 'label' => 'Journal page: heading', 'default' => 'Journal'],
            'journal.text'     => ['type' => 'textarea', 'label' => 'Journal page: text', 'default' => 'News, events and stories from the hotel and the city.'],
            'about.title'      => ['type' => 'text', 'label' => 'About page: heading', 'default' => 'Our Story'],
            'about.text'       => ['type' => 'textarea', 'label' => 'About page: text', 'default' => "We opened our doors with a simple idea: that a hotel should feel like the best version of home.\n\nToday our team welcomes travellers, families and businesses from all over the world, with the same warmth as on the first day.", 'help' => 'Leave a blank line between paragraphs.'],
            'about.image'      => ['type' => 'image', 'label' => 'About page: photo'],
            'contact.title'    => ['type' => 'text', 'label' => 'Contact page: heading', 'default' => 'Contact Us'],
            'contact.text'     => ['type' => 'textarea', 'label' => 'Contact page: text', 'default' => 'Questions about a stay, an event or a table? Send us a message and we will get back to you within a day.'],
            'contact.image'    => ['type' => 'image', 'label' => 'Contact page: photo'],
        ]],
        'contact' => ['Contact & social', 'ri-share-line', [
            'contact.whatsapp'   => ['type' => 'text', 'label' => 'WhatsApp number', 'help' => 'With the country code, e.g. +234 803 123 4567. Shows a chat button on every page.'],
            'contact.hours'      => ['type' => 'text', 'label' => 'Reception hours', 'default' => 'Open 24 hours, every day'],
            'contact.directions' => ['type' => 'textarea', 'label' => 'Getting here', 'help' => 'From the airport, parking, landmarks …'],
            'contact.map'        => ['type' => 'bool', 'label' => 'Show a map (from the hotel\'s map position under Settings → Hotels)', 'default' => true],
            'social.instagram'   => ['type' => 'url', 'label' => 'Instagram'],
            'social.facebook'    => ['type' => 'url', 'label' => 'Facebook'],
            'social.x'           => ['type' => 'url', 'label' => 'X (Twitter)'],
            'social.tiktok'      => ['type' => 'url', 'label' => 'TikTok'],
            'social.youtube'     => ['type' => 'url', 'label' => 'YouTube'],
            'social.linkedin'    => ['type' => 'url', 'label' => 'LinkedIn'],
            'social.tripadvisor' => ['type' => 'url', 'label' => 'Tripadvisor'],
        ]],
        'seo' => ['Search & sharing', 'ri-search-eye-line', [
            'seo.title'       => ['type' => 'text', 'label' => 'Title in search results', 'help' => 'Empty: the hotel\'s name and tagline.'],
            'seo.description' => ['type' => 'textarea', 'label' => 'Description in search results', 'help' => 'About 155 characters.'],
            'seo.image'       => ['type' => 'image', 'label' => 'Picture when a link is shared', 'help' => 'Shown on WhatsApp, Facebook, X … 1200 × 630 px works best.'],
            'footer.text'     => ['type' => 'textarea', 'label' => 'Footer text', 'help' => 'A sentence or two under the logo in the footer.'],
        ]],
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::all();
        if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
            return $data[$key];
        }

        return $default ?? self::field($key)['default'] ?? null;
    }

    /** Saved values for the current hotel (keys as in FIELDS), read once per request. */
    public static function all(): array
    {
        $id = (int) CurrentHotel::get()->id();
        $key = self::cacheKey($id);
        $app = app();
        if (! $app->bound($key)) {
            $data = Cache::remember($key, 3600, function () {
                try {
                    return Schema::hasTable('website_settings') ? (WebsiteSetting::query()->value('data') ?? []) : [];
                } catch (Throwable) {
                    return [];
                }
            });
            // value() returns the column's raw JSON.
            $app->instance($key, is_string($data) ? (json_decode($data, true) ?: []) : (array) $data);
        }

        return $app->make($key);
    }

    public static function save(array $values, ?int $userId = null): void
    {
        $row = WebsiteSetting::firstOrNew([]);
        $row->data = array_merge($row->data ?? [], $values);
        $row->updated_by = $userId;
        $row->save();
        self::forget();
    }

    public static function forget(): void
    {
        $key = self::cacheKey((int) CurrentHotel::get()->id());
        Cache::forget($key);
        app()->forgetInstance($key);
    }

    public static function field(string $key): ?array
    {
        foreach (self::FIELDS as [, , $fields]) {
            if (isset($fields[$key])) {
                return $fields[$key];
            }
        }

        return null;
    }

    public static function enabled(): bool
    {
        return (bool) self::get('enabled', true);
    }

    /** [accent, accent dark, ink, paper] of the chosen colours. */
    public static function theme(): array
    {
        $t = self::THEMES[self::get('theme')] ?? self::THEMES['champagne'];

        return ['key' => self::get('theme'), 'accent' => $t[1], 'accent_dark' => $t[2], 'ink' => $t[3], 'paper' => $t[4]];
    }

    /** A saved picture's address, or null. */
    public static function image(string $key): ?string
    {
        $path = self::get($key);
        if (! $path || ! is_string($path)) {
            return null;
        }

        return asset($path);
    }

    /** Saves an uploaded picture under public/uploads/website (works without a storage link). */
    public static function storeUpload(\Illuminate\Http\UploadedFile $file, string $prefix): string
    {
        $dir = public_path('uploads/website');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = \Illuminate\Support\Str::slug($prefix) . '-' . now()->format('YmdHis') . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6))
            . '.' . strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $file->move($dir, $name);

        return 'uploads/website/' . $name;
    }

    /** Removes a picture saved by storeUpload (never the theme's own). */
    public static function deleteUpload(?string $path): void
    {
        if ($path && str_starts_with($path, 'uploads/website/') && ! str_contains($path, '..') && is_file(public_path($path))) {
            @unlink(public_path($path));
        }
    }

    /** "number|words" lines → [[number, words]]. */
    public static function stats(): array
    {
        return collect((array) self::get('home.stats', []))
            ->map(fn ($l) => array_map('trim', explode('|', (string) $l, 2)))
            ->filter(fn ($p) => count($p) === 2 && $p[0] !== '')->values()->all();
    }

    /** Social links that are filled in: key => [label, url, icon]. */
    public static function socials(): array
    {
        $icons = ['instagram' => 'ri-instagram-line', 'facebook' => 'ri-facebook-circle-line', 'x' => 'ri-twitter-x-line', 'tiktok' => 'ri-tiktok-line',
            'youtube' => 'ri-youtube-line', 'linkedin' => 'ri-linkedin-box-line', 'tripadvisor' => 'ri-compass-discover-line'];
        $out = [];
        foreach ($icons as $k => $icon) {
            if ($url = self::get('social.' . $k)) {
                $out[$k] = [self::FIELDS['contact'][2]['social.' . $k]['label'], $url, $icon];
            }
        }

        return $out;
    }

    /** The hotel's contact details, from Online Booking settings and the hotel's own record. */
    public static function contact(): array
    {
        $hotel = CurrentHotel::get()->hotel();
        $wa = preg_replace('/[^0-9]/', '', (string) self::get('contact.whatsapp'));

        return [
            'name'     => config('hotel.name'),
            'address'  => config('hotel.booking.address') ?: $hotel?->address,
            'phone'    => config('hotel.booking.contact_phone') ?: $hotel?->phone,
            'email'    => config('hotel.booking.contact_email') ?: $hotel?->email,
            'whatsapp' => $wa ? 'https://wa.me/' . $wa : null,
            'whatsapp_number' => self::get('contact.whatsapp'),
            'hours'    => self::get('contact.hours'),
            'lat'      => $hotel?->latitude,
            'lng'      => $hotel?->longitude,
            'check_in'  => config('hotel.booking.check_in_time', '14:00'),
            'check_out' => config('hotel.booking.check_out_time', '12:00'),
        ];
    }

    private static function cacheKey(int $id): string
    {
        return 'website.settings.' . $id;
    }
}
