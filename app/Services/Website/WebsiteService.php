<?php

namespace App\Services\Website;

use App\Models\Events\Event;
use App\Models\Events\Hall;
use App\Models\RoomType;
use App\Models\Website\WebsiteItem;
use App\Models\Website\WebsiteMessage;
use App\Models\Website\WebsiteSubscriber;
use App\Support\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** What the public website and the guest app's home screen show, and what their forms send. */
class WebsiteService
{
    /** Live items of one kind, in the order set under Website. */
    public function items(string $kind, ?int $limit = null): Collection
    {
        return WebsiteItem::ofKind($kind)->live()->ordered()->when($limit, fn ($q) => $q->limit($limit))->get();
    }

    /** Room types on sale, cheapest first, with their photos. */
    public function rooms(): Collection
    {
        return RoomType::where('is_active', true)->with('photos')
            ->withCount(['rooms' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('base_rate')->get()->filter(fn ($t) => $t->rooms_count > 0)->values();
    }

    public function halls(): Collection
    {
        return Schema::hasTable('halls') ? Hall::active()->ordered()->get() : collect();
    }

    /** Confirmed events with tickets on sale, soonest first. */
    public function upcomingEvents(int $limit = 6): Collection
    {
        if (! Schema::hasColumn('events', 'is_ticketed')) {
            return collect();
        }

        return Event::with(['hall', 'ticketTypes' => fn ($q) => $q->where('is_active', true)->orderBy('price')])
            ->where('is_ticketed', true)->where('ticket_sales_open', true)->where('status', 'confirmed')->where('ends_at', '>', now())
            ->orderBy('starts_at')->limit($limit)->get();
    }

    /** Saves a message from a website or app form and e-mails it to the hotel. */
    public function receive(array $data, string $kind, ?string $ip = null): WebsiteMessage
    {
        $details = array_map('strval', array_filter(array_intersect_key($data, array_flip(['date', 'time', 'guests', 'event_type', 'venue', 'hall', 'source'])), 'filled'));
        $subject = $data['subject'] ?? match ($kind) {
            'event'  => 'Event enquiry' . (! empty($data['event_type']) ? ': ' . $data['event_type'] : ''),
            'dining' => 'Table for ' . ($data['guests'] ?? '?') . (! empty($data['venue']) ? ' at ' . $data['venue'] : ''),
            default  => 'Message from the website',
        };
        $m = WebsiteMessage::create([
            'kind' => $kind, 'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
            'subject' => $subject, 'message' => $data['message'] ?? '', 'details' => $details ?: null, 'ip' => $ip,
        ]);

        $to = Website::get('notify_email') ?: config('hotel.booking.contact_email');
        if ($to) {
            try {
                $lines = collect($m->detail_lines)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n");
                Mail::raw(
                    "{$m->kind_label} from {$m->name}\nE-mail: {$m->email}" . ($m->phone ? "\nPhone: {$m->phone}" : '') . ($lines ? "\n{$lines}" : '')
                        . "\n\n{$m->message}\n\nAnswer it and mark it done under Website → Enquiries: " . route('website.enquiries.show', $m),
                    fn ($mail) => $mail->to($to)->replyTo($m->email, $m->name)->subject('[' . config('hotel.name') . '] ' . $subject)
                );
            } catch (Throwable $e) {
                Log::warning('Website enquiry e-mail not sent: ' . $e->getMessage());
            }
        }

        return $m;
    }

    /** Newsletter sign-up; signing up again after leaving the list puts them back on it. */
    public function subscribe(string $email, ?string $name = null, string $source = 'website'): WebsiteSubscriber
    {
        $s = WebsiteSubscriber::firstOrNew(['email' => mb_strtolower(trim($email))]);
        $s->fill(['name' => $name ?: $s->name, 'source' => $s->exists ? $s->source : $source, 'unsubscribed_at' => null])->save();

        return $s;
    }

    // ── For the guest app ─────────────────────────────────────────────

    /** Everything the guest app's home screen shows, in one call. */
    public function appHome(): array
    {
        $sym = config('hotel.currency_symbol');
        $img = fn (?string $u) => $u ? url($u) : null;
        $item = fn (WebsiteItem $i) => array_filter([
            'id' => $i->id, 'title' => $i->title, 'subtitle' => $i->subtitle, 'category' => $i->category, 'summary' => $i->summary,
            'body' => $i->body, 'image' => $i->image_url, 'icon' => $i->icon ? str_replace(['ri-', '-line'], '', $i->icon) : null,
            'meta' => $i->meta, 'promo_code' => $i->promo_code, 'rating' => $i->rating, 'featured' => $i->is_featured ?: null,
            'ends_on' => $i->ends_on?->toDateString(), 'date' => $i->published_at?->toDateString(),
            'link_label' => $i->link_label, 'link_url' => $i->link_url ? (str_starts_with($i->link_url, '/') ? url($i->link_url) : $i->link_url) : null,
            'url' => $i->url,
        ], fn ($v) => $v !== null && $v !== '');
        $theme = Website::theme();
        $contact = Website::contact();

        return [
            'enabled' => Website::enabled(),
            'hotel' => [
                'name' => config('hotel.name'), 'tagline' => Website::get('tagline'),
                'logo' => $img(config('hotel.logo_light') ? asset(config('hotel.logo_light')) : null),
                'website' => route('home'),
            ],
            'theme' => ['accent' => $theme['accent'], 'accent_dark' => $theme['accent_dark'], 'ink' => $theme['ink'], 'paper' => $theme['paper']],
            'announcement' => Website::get('announcement') ? ['text' => Website::get('announcement'), 'url' => Website::get('announcement_url')] : null,
            'intro' => [
                'eyebrow' => Website::get('home.intro_eyebrow'), 'title' => Website::get('home.intro_title'),
                'text' => Website::get('home.intro_text'), 'image' => Website::image('home.intro_image'),
            ],
            'stats' => array_map(fn ($s) => ['value' => $s[0], 'label' => $s[1]], Website::stats()),
            'slides' => $this->items('slide', 6)->map($item)->values(),
            'highlights' => $this->items('amenity', 12)->map($item)->values(),
            'rooms' => $this->rooms()->map(fn (RoomType $t) => [
                'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'from' => (float) $t->base_rate,
                'from_label' => $sym . number_format((float) $t->base_rate), 'sleeps' => $t->capacity_label, 'bed' => $t->bed_type,
                'size_sqm' => $t->size_sqm ? (float) $t->size_sqm : null, 'amenities' => array_values((array) $t->amenities),
                'photos' => $t->photos->map(fn ($p) => $p->url)->values(), 'url' => route('site.rooms.show', $t->id),
            ])->values(),
            'offers' => $this->items('offer', 8)->map($item)->values(),
            'dining' => $this->items('dining', 8)->map($item)->values(),
            'experiences' => $this->items('experience', 8)->map($item)->values(),
            'events' => $this->upcomingEvents(4)->map(fn (Event $e) => [
                'id' => $e->id, 'title' => $e->title, 'when' => $e->when, 'starts_at' => $e->starts_at->toIso8601String(), 'hall' => $e->hall?->name,
                'info' => $e->ticket_info, 'from' => $e->ticketTypes->first() ? $sym . number_format((float) $e->ticketTypes->first()->price) : null,
            ])->values(),
            'gallery' => $this->items('gallery', 18)->map($item)->values(),
            'reviews' => $this->items('review', 8)->map($item)->values(),
            'posts' => $this->items('post', 4)->map(fn ($i) => array_diff_key($item($i), ['body' => 1]))->values(),
            'contact' => array_filter([
                'address' => $contact['address'], 'phone' => $contact['phone'], 'email' => $contact['email'], 'whatsapp' => $contact['whatsapp'],
                'hours' => $contact['hours'], 'lat' => $contact['lat'], 'lng' => $contact['lng'],
                'check_in' => $contact['check_in'], 'check_out' => $contact['check_out'],
            ], fn ($v) => $v !== null && $v !== ''),
            'socials' => collect(Website::socials())->map(fn ($s, $k) => ['key' => $k, 'label' => $s[0], 'url' => $s[1]])->values(),
            'links' => [
                'rooms' => route('site.rooms'), 'dining' => route('site.dining'), 'events' => route('site.events'), 'offers' => route('site.offers'),
                'experiences' => route('site.experiences'), 'gallery' => route('site.gallery'), 'journal' => route('site.journal'), 'contact' => route('site.contact'),
                'about' => route('site.about'),
            ],
            'newsletter' => (bool) Website::get('home.show_newsletter'),
        ];
    }
}
