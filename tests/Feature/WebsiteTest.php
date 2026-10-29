<?php

use App\Models\User;
use App\Models\Website\WebsiteItem;
use App\Models\Website\WebsiteMessage;
use App\Models\Website\WebsiteSubscriber;
use App\Support\Website;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoomDemoSeeder;
use Database\Seeders\WebsiteDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(RoomDemoSeeder::class);
    $this->seed(WebsiteDemoSeeder::class);
    auth()->logout();
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->gm = $make('Gbenga Manager', 'General Manager');
    $this->desk = $make('Ada Desk', 'Front Desk');
    $this->cashier = $make('Kunle Cash', 'Cashier');
});

afterEach(function () {
    foreach (glob(public_path('uploads/website/*-test-*')) ?: [] as $f) {
        @unlink($f);
    }
});

test('every page of the website opens, with rooms from Room Types and links to online booking', function () {
    $offer = WebsiteItem::ofKind('offer')->first();
    $room = \App\Models\RoomType::first();
    $home = $this->get('/')->assertOk()->assertSee('Check availability')->assertSee(route('book.search'), false)->assertSee($room->name);
    foreach (['/accommodation', '/accommodation/' . $room->id, '/dining', '/meetings-events', '/experiences', '/offers', '/gallery', '/journal', '/about', '/contact',
        $offer->url, WebsiteItem::ofKind('post')->first()->url, WebsiteItem::ofKind('dining')->first()->url, WebsiteItem::ofKind('experience')->first()->url, WebsiteItem::ofKind('page')->first()->url] as $url) {
        $this->get($url)->assertOk();
    }
    // An offer's booking form carries its promo code to /book.
    $this->get($offer->url)->assertSee('name="promo" value="' . $offer->promo_code . '"', false);
    // The room page lands on that room in the booking results.
    $this->get('/accommodation/' . $room->id)->assertSee('#t' . $room->id, false);
});

test('hidden, expired and future content stays off the website', function () {
    $offer = WebsiteItem::ofKind('offer')->first();
    $offer->update(['is_active' => false]);
    $this->get('/offers')->assertDontSee($offer->title);
    $this->get($offer->url)->assertNotFound();

    $offer->update(['is_active' => true, 'ends_on' => today()->subDay()]);
    $this->get('/offers')->assertDontSee($offer->title);

    $post = WebsiteItem::ofKind('post')->first();
    $post->update(['published_at' => now()->addDays(2)]);
    $this->get('/journal')->assertDontSee($post->title);
});

test('switched off, the website pages are gone and the main address opens sign-in', function () {
    Website::save(['enabled' => false]);
    $this->get('/')->assertRedirect(route('login'));
    $this->get('/offers')->assertNotFound();
    $this->get('/book')->assertOk();   // online booking keeps working
});

test('the contact, event and table forms are saved for the team and e-mailed', function () {
    Mail::fake();
    Website::save(['notify_email' => 'events@hotel.test']);
    $this->post('/contact', ['kind' => 'event', 'name' => 'Ngozi', 'email' => 'ngozi@example.com', 'event_type' => 'Wedding', 'date' => today()->addMonth()->toDateString(), 'guests' => 250, 'message' => 'Saturday please'])
        ->assertRedirect()->assertSessionHas('sent');
    $m = WebsiteMessage::sole();
    expect($m->kind)->toBe('event')->and($m->details['guests'])->toBe('250')->and($m->subject)->toBe('Event enquiry: Wedding')->and($m->status)->toBe('new');

    // Dates in the past and a missing message on the contact form are refused.
    $this->post('/contact', ['kind' => 'dining', 'name' => 'A', 'email' => 'a@example.com', 'date' => today()->subDay()->toDateString(), 'guests' => 2])->assertSessionHasErrors('date');
    $this->post('/contact', ['kind' => 'contact', 'name' => 'A', 'email' => 'a@example.com'])->assertSessionHasErrors('message');
    // Bots that fill the hidden field are dropped quietly.
    $this->post('/contact', ['kind' => 'contact', 'name' => 'Bot', 'email' => 'b@example.com', 'message' => 'spam', 'website' => 'http://spam'])->assertRedirect();
    expect(WebsiteMessage::count())->toBe(1);
});

test('newsletter sign-ups are kept once per address', function () {
    $this->post('/newsletter', ['email' => 'Ola@Example.com'])->assertRedirect()->assertSessionHas('subscribed');
    $this->post('/newsletter', ['email' => 'ola@example.com'])->assertRedirect();
    expect(WebsiteSubscriber::count())->toBe(1)->and(WebsiteSubscriber::first()->email)->toBe('ola@example.com');
});

test('a manager adds, edits, orders and hides website content', function () {
    $this->actingAs($this->gm);
    $this->get('/website')->assertOk();
    $this->get('/website/content/offer')->assertOk()->assertSee('Stay longer, pay less');
    $this->get('/website/content/offer/new')->assertOk();

    $this->post('/website/content/offer', [
        'title' => 'Honeymoon', 'summary' => 'Flowers and breakfast in bed.', 'meta' => 'From ₦120,000', 'promo_code' => 'love26',
        'starts_on' => today()->toDateString(), 'is_active' => 1, 'image' => UploadedFile::fake()->image('honeymoon-test-x.jpg', 1600, 1000),
    ])->assertRedirect(route('website.content.index', 'offer'));
    $o = WebsiteItem::where('title', 'Honeymoon')->sole();
    expect($o->promo_code)->toBe('LOVE26')->and($o->slug)->toBe('honeymoon')->and($o->image)->toStartWith('uploads/website/')
        ->and(is_file(public_path($o->image)))->toBeTrue();
    $this->get('/offers/honeymoon')->assertOk()->assertSee('LOVE26');

    $this->put("/website/content/offer/{$o->id}", ['title' => 'Honeymoon escape', 'slug' => 'honeymoon', 'summary' => 'x', 'is_active' => 1])->assertRedirect();
    expect($o->fresh()->title)->toBe('Honeymoon escape')->and($o->fresh()->image)->toBe($o->image);

    $first = WebsiteItem::ofKind('offer')->orderBy('sort_order')->first();
    $this->post("/website/content/offer/{$o->id}/move", ['dir' => 'up']);
    expect(WebsiteItem::ofKind('offer')->orderBy('sort_order')->get()->search(fn ($i) => $i->id === $o->id))->toBe(WebsiteItem::ofKind('offer')->count() - 2);

    $this->post("/website/content/offer/{$o->id}/toggle")->assertRedirect();
    expect($o->fresh()->is_active)->toBeFalse();

    // A slide needs a photo; a link must be a web address or a page of the site.
    $this->post('/website/content/slide', ['title' => 'No photo', 'is_active' => 1])->assertSessionHasErrors('image');
    $this->post('/website/content/dining', ['title' => 'Bar', 'link_label' => 'Menu', 'link_url' => 'javascript:alert(1)'])->assertSessionHasErrors('link_url');
    $this->get('/website/content/nonsense')->assertNotFound();
    $this->get("/website/content/slide/{$o->id}/edit")->assertNotFound();   // an offer is not a slide

    $path = $o->fresh()->image;
    $this->delete("/website/content/offer/{$o->id}")->assertRedirect();
    expect(WebsiteItem::find($o->id))->toBeNull()->and(is_file(public_path($path)))->toBeFalse();
    expect(\App\Models\AuditLog::where('action', 'like', 'website.%')->count())->toBeGreaterThanOrEqual(3);
});

test('a manager changes the website settings and texts', function () {
    $this->actingAs($this->gm);
    $this->get('/website/settings/home')->assertOk();
    $this->post('/website/settings/home', ['home__intro_title' => 'Welcome to the river house', 'home__stats' => "120|Rooms\n3|Pools", 'home__show_gallery' => 0])->assertRedirect();
    expect(Website::get('home.intro_title'))->toBe('Welcome to the river house')->and(Website::stats())->toBe([['120', 'Rooms'], ['3', 'Pools']])
        ->and(Website::get('home.show_gallery'))->toBeFalse();
    $this->get('/')->assertSee('Welcome to the river house')->assertSee('Pools');

    $this->post('/website/settings/general', ['enabled' => 1, 'theme' => 'emerald', 'tagline' => 'Stay well', 'notify_email' => 'not-an-email'])->assertSessionHasErrors();
    $this->post('/website/settings/general', ['enabled' => 1, 'theme' => 'emerald', 'tagline' => 'Stay well'])->assertRedirect();
    $this->get('/')->assertSee(Website::THEMES['emerald'][1]);
});

test('front desk read and answer enquiries but cannot change the website; others cannot see either', function () {
    $m = app(\App\Services\Website\WebsiteService::class)->receive(['name' => 'Tunde', 'email' => 't@example.com', 'message' => 'Do you have parking?'], 'contact');
    $this->actingAs($this->desk);
    $this->get('/website/enquiries')->assertOk()->assertSee('Tunde');
    $this->get("/website/enquiries/{$m->id}")->assertOk()->assertSee('Do you have parking?');
    expect($m->fresh()->read_at)->not->toBeNull();
    $this->put("/website/enquiries/{$m->id}", ['status' => 'replied', 'note' => 'Yes, told them'])->assertRedirect();
    expect($m->fresh()->status)->toBe('replied')->and($m->fresh()->handled_by)->toBe($this->desk->id);
    $this->get('/website/content/offer')->assertForbidden();
    $this->get('/website/settings')->assertForbidden();

    $this->actingAs($this->cashier);
    $this->get('/website')->assertForbidden();
    $this->get('/website/enquiries')->assertForbidden();
});

test('the guest app gets the website content and can send enquiries and sign up', function () {
    $res = $this->getJson('/api/v1/guest/website')->assertOk()
        ->assertJsonStructure(['data' => ['enabled', 'hotel' => ['name', 'tagline', 'website'], 'theme' => ['accent', 'ink', 'paper'], 'intro' => ['title', 'text'],
            'slides' => [['title', 'image']], 'highlights' => [['title', 'icon']], 'rooms' => [['id', 'name', 'from_label', 'photos', 'url']],
            'offers' => [['title', 'promo_code']], 'dining', 'experiences', 'gallery', 'reviews', 'posts', 'contact', 'links' => ['rooms', 'offers', 'contact']]]);
    expect($res->json('data.highlights.0.icon'))->toBe('wifi')->and($res->json('data.slides'))->toHaveCount(3);

    $this->postJson('/api/v1/guest/website/enquiries', ['kind' => 'dining', 'name' => 'Ife', 'email' => 'ife@example.com', 'date' => today()->toDateString(), 'time' => '19:00', 'guests' => 4, 'venue' => 'The Terrace'])
        ->assertCreated()->assertJson(['success' => true]);
    expect(WebsiteMessage::sole()->details)->toMatchArray(['guests' => '4', 'venue' => 'The Terrace', 'source' => 'Guest app']);
    $this->postJson('/api/v1/guest/website/subscribe', ['email' => 'ife@example.com'])->assertOk();
    expect(WebsiteSubscriber::sole()->source)->toBe('app');
});

test('each hotel has its own website content', function () {
    \App\Support\HotelSettings::putGroup('multi_hotel', true);
    \App\Support\HotelSettings::apply();
    $second = \App\Models\Hotel::create(['name' => 'River House', 'slug' => 'river-house', 'is_active' => true]);
    \App\Support\Tenancy\CurrentHotel::get()->run($second, function () {
        Website::save(['home.intro_title' => 'The river house welcome']);
        WebsiteItem::create(['kind' => 'offer', 'title' => 'River offer only', 'is_active' => true]);
    });
    $this->get('/offers')->assertOk()->assertDontSee('River offer only');
    $this->get('/offers?hotel=river-house')->assertOk()->assertSee('River offer only');
    $this->get('/?hotel=river-house')->assertSee('The river house welcome');
});
