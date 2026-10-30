<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Models\Website\WebsiteItem;
use App\Services\Website\WebsiteService;
use App\Support\Website;
use Illuminate\Http\Request;

/**
 * The hotel's public website. Content comes from Website in the sidebar; rooms, halls and events from
 * their own modules; every "Book" button goes to the online booking pages (/book).
 */
class SiteController extends Controller
{
    public function __construct(private WebsiteService $site)
    {
    }

    public function home()
    {
        if (! Website::enabled()) {
            return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
        }
        $s = $this->site;

        return view('site.home', [
            'slides'      => $s->items('slide', 6),
            'highlights'  => $s->items('amenity', 8),
            'rooms'       => Website::get('home.show_rooms') ? $s->rooms()->take(6) : collect(),
            'offers'      => Website::get('home.show_offers') ? $s->items('offer', 6) : collect(),
            'dining'      => Website::get('home.show_dining') ? $s->items('dining', 4) : collect(),
            'experiences' => Website::get('home.show_experiences') ? $s->items('experience', 6) : collect(),
            'halls'       => Website::get('home.show_events') ? $s->halls() : collect(),
            'events'      => Website::get('home.show_events') ? $s->upcomingEvents(3) : collect(),
            'gallery'     => Website::get('home.show_gallery') ? $s->items('gallery', 10) : collect(),
            'reviews'     => Website::get('home.show_reviews') ? $s->items('review', 8) : collect(),
            'posts'       => Website::get('home.show_journal') ? $s->items('post', 3) : collect(),
        ]);
    }

    public function rooms()
    {
        return view('site.rooms', ['rooms' => $this->site->rooms(), 'highlights' => $this->site->items('amenity', 8)]);
    }

    public function room(int $type)
    {
        $room = RoomType::where('is_active', true)->with('photos')->findOrFail($type);
        $others = $this->site->rooms()->reject(fn ($t) => $t->id === $room->id)->take(3);

        return view('site.room', compact('room', 'others'));
    }

    public function dining()
    {
        return view('site.dining', ['venues' => $this->site->items('dining')]);
    }

    public function events()
    {
        return view('site.events', ['halls' => $this->site->halls(), 'events' => $this->site->upcomingEvents(9),
            'gallery' => WebsiteItem::ofKind('gallery')->live()->whereIn('category', ['Events', 'Weddings', 'Meetings', 'Conferences'])->ordered()->limit(6)->get()]);
    }

    public function experiences()
    {
        return view('site.listing', ['kind' => 'experience', 'items' => $this->site->items('experience'), 'key' => 'experiences']);
    }

    public function offers()
    {
        return view('site.listing', ['kind' => 'offer', 'items' => $this->site->items('offer'), 'key' => 'offers']);
    }

    public function journal()
    {
        $items = WebsiteItem::ofKind('post')->live()->orderByDesc('is_featured')->orderByDesc('published_at')->paginate(9);

        return view('site.listing', ['kind' => 'post', 'items' => $items, 'key' => 'journal']);
    }

    public function gallery()
    {
        $photos = $this->site->items('gallery');

        return view('site.gallery', ['photos' => $photos, 'categories' => $photos->pluck('category')->filter()->unique()->values()]);
    }

    /** One restaurant, experience, offer or journal post. */
    public function item(Request $request, string $slug)
    {
        $kind = $request->route()->defaults['kind'];
        $item = WebsiteItem::ofKind($kind)->live()->where('slug', $slug)->firstOrFail();
        $more = WebsiteItem::ofKind($kind)->live()->whereKeyNot($item->id)->ordered()->limit(3)->get();

        return view('site.item', compact('item', 'more', 'kind'));
    }

    public function page(string $slug)
    {
        $item = WebsiteItem::ofKind('page')->live()->where('slug', $slug)->firstOrFail();

        return view('site.item', ['item' => $item, 'more' => collect(), 'kind' => 'page']);
    }

    /** Get our app: store links, the Android file and how to install it. */
    public function app()
    {
        abort_unless(Website::get('apps.show') && Website::apps(), 404);

        return view('site.app', ['apps' => Website::apps()]);
    }

    /** Sends an uploaded Android app (.apk) as a download. */
    public function download(string $app)
    {
        abort_if($app === 'staff' && ! Website::get('apps.staff.show'), 404);
        $file = Website::appFile($app);
        abort_unless($file, 404);
        $name = \Illuminate\Support\Str::slug(config('hotel.name') . ' ' . $app) . (($v = Website::get("apps.$app.version")) ? '-' . preg_replace('/[^0-9A-Za-z.]+/', '', $v) : '') . '.apk';

        return response()->download(storage_path('app/' . $file['path']), $name, ['Content-Type' => 'application/vnd.android.package-archive']);
    }

    public function about()
    {
        return view('site.about', ['highlights' => $this->site->items('amenity', 8), 'reviews' => $this->site->items('review', 6),
            'gallery' => $this->site->items('gallery', 6)]);
    }

    public function contact()
    {
        return view('site.contact', ['faqs' => $this->site->items('faq'), 'contact' => Website::contact()]);
    }

    /** Contact, event enquiry and table booking forms. */
    public function send(Request $request)
    {
        // A hidden field real people never fill in: quietly drop bot submissions.
        if (filled($request->input('website'))) {
            return back()->with('sent', 'Thank you. We have your message.');
        }
        $kind = in_array($request->input('kind'), ['contact', 'event', 'dining'], true) ? $request->input('kind') : 'contact';
        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'email'      => 'required|email:rfc|max:150',
            'phone'      => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'subject'    => 'nullable|string|max:160',
            'message'    => $kind === 'contact' ? 'required|string|max:4000' : 'nullable|string|max:4000',
            'date'       => [$kind === 'contact' ? 'nullable' : 'required', 'date', 'after_or_equal:today'],
            'time'       => 'nullable|date_format:H:i',
            'guests'     => [$kind === 'contact' ? 'nullable' : 'required', 'integer', 'min:1', 'max:5000'],
            'event_type' => 'nullable|string|max:60',
            'venue'      => 'nullable|string|max:120',
            'hall'       => 'nullable|string|max:120',
        ], ['phone.regex' => 'Enter a valid phone number.', 'date.after_or_equal' => 'Choose today or a later date.']);
        $this->site->receive($data, $kind, $request->ip());

        $thanks = match ($kind) {
            'event'  => 'Thank you, ' . $data['name'] . '. Our events team will be in touch within one working day.',
            'dining' => 'Thank you, ' . $data['name'] . '. We will confirm your table by e-mail or phone shortly.',
            default  => 'Thank you, ' . $data['name'] . '. We will get back to you soon.',
        };

        return back()->with('sent', $thanks)->withFragment($request->input('return_to', 'form'));
    }

    public function subscribe(Request $request)
    {
        if (filled($request->input('website'))) {
            return back()->with('subscribed', true);
        }
        $data = $request->validate(['email' => 'required|email:rfc|max:150', 'name' => 'nullable|string|max:120']);
        $this->site->subscribe($data['email'], $data['name'] ?? null);

        return back()->with('subscribed', true)->withFragment('newsletter');
    }
}
