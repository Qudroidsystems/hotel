<?php

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestFeedback;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Website\WebsiteItem;
use App\Services\Booking\FeedbackService;
use App\Services\Booking\OnlineBookingService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    $this->withoutDefer();
    Mail::fake();
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->gm = $make('Grace Manager', 'General Manager');
    $this->desk = $make('Dele Desk', 'Front Desk');
    $this->cleaner = $make('Bisi Cleaner', 'Housekeeping');

    $this->r = Reservation::where('status', ReservationStatus::Confirmed->value)->with('guest')->first();
    $this->r->guest->forceFill(['email' => 'ada@guest.test'])->save();
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
    $this->checkOut = function (?\Carbon\Carbon $at = null) {
        $this->r->forceFill(['status' => ReservationStatus::CheckedOut, 'check_in_date' => today()->subDays(2), 'check_out_date' => today(), 'checked_out_at' => $at ?? now()])->save();
    };
});

test('a guest can only rate the stay after checking out, once, within the window', function () {
    $this->get(route('stay.feedback', $this->token))->assertOk()->assertSee('once you have checked out');
    $this->getJson("/api/v1/guest/stays/{$this->token}")->assertOk()->assertJsonPath('data.feedback.can_rate', false);
    $this->postJson("/api/v1/guest/stays/{$this->token}/feedback", ['overall' => 5])->assertStatus(422);

    ($this->checkOut)();
    $this->get(route('stay.show', $this->token))->assertOk()->assertSee('Rate my stay');
    $this->get(route('stay.feedback', $this->token))->assertOk()->assertSee('Your stay overall')->assertSee('Value for money');

    $this->post(route('stay.feedback.store', $this->token), [
        'overall' => 4, 'ratings' => ['room' => 4, 'cleanliness' => 5, 'staff' => 5, 'food' => 3, 'value' => 4, 'nonsense' => 1],
        'recommend' => 9, 'comment' => 'Lovely staff, the jollof could be hotter.', 'may_quote' => 1,
    ])->assertRedirect(route('stay.feedback', $this->token));

    $f = GuestFeedback::sole();
    expect($f->overall)->toBe(4)->and($f->ratings)->toBe(['room' => 4, 'cleanliness' => 5, 'staff' => 5, 'food' => 3, 'value' => 4])
        ->and($f->recommend)->toBe(9)->and($f->nps_group)->toBe('promoter')->and($f->may_quote)->toBeTrue()
        ->and($f->status)->toBe('new')->and($f->source)->toBe('web')->and($f->guest_id)->toBe($this->r->guest_id);

    // Second time: refused, and the page shows what they sent.
    $this->postJson("/api/v1/guest/stays/{$this->token}/feedback", ['overall' => 1])->assertStatus(422)->assertJsonPath('message', 'Thank you, we already have your feedback for this stay.');
    $this->get(route('stay.feedback', $this->token))->assertOk()->assertSee('the jollof could be hotter');
    $this->getJson("/api/v1/guest/stays/{$this->token}/feedback")->assertOk()->assertJsonPath('data.given.overall', 4)->assertJsonPath('data.can_rate', false);
    expect(GuestFeedback::count())->toBe(1);
});

test('the survey closes after the window', function () {
    config(['hotel.feedback.window_days' => 30]);
    ($this->checkOut)(now()->subDays(31));

    $this->getJson("/api/v1/guest/stays/{$this->token}/feedback")->assertOk()->assertJsonPath('data.available', false);
    $this->postJson("/api/v1/guest/stays/{$this->token}/feedback", ['overall' => 5])->assertStatus(422)->assertJsonPath('message', 'This survey has closed.');
});

test('the app sends feedback; a low score is flagged for follow-up and a manager handles it', function () {
    ($this->checkOut)();
    $this->postJson("/api/v1/guest/stays/{$this->token}/feedback", ['overall' => 0])->assertStatus(422);
    $this->postJson("/api/v1/guest/stays/{$this->token}/feedback", ['overall' => 2, 'recommend' => 3, 'comment' => 'AC was broken all night.', 'contact_ok' => true])
        ->assertCreated()->assertJsonPath('data.given.overall', 2)->assertJsonPath('data.review_url', null);

    $f = GuestFeedback::sole();
    expect($f->status)->toBe('follow_up')->and($f->source)->toBe('app')->and($f->contact_ok)->toBeTrue();

    $this->actingAs($this->desk)->get(route('feedback.index'))->assertOk()->assertSee('AC was broken all night.')->assertSee('Needs follow-up');
    $this->actingAs($this->desk)->post(route('feedback.handle', $f), ['status' => 'resolved', 'note' => 'x'])->assertForbidden();
    $this->actingAs($this->cleaner)->get(route('feedback.index'))->assertForbidden();

    $this->actingAs($this->gm)->get(route('feedback.show', $f))->assertOk()->assertSee('This guest was unhappy');
    $this->actingAs($this->gm)->post(route('feedback.handle', $f), ['status' => 'resolved', 'note' => 'Called, apologised, AC in the room serviced.'])->assertSessionHas('success');
    $f->refresh();
    expect($f->status)->toBe('resolved')->and($f->handled_by)->toBe($this->gm->id)->and($f->follow_up_note)->toContain('apologised');
    $this->assertDatabaseHas('audit_logs', ['action' => 'guest.feedback_handled']);
});

test('happy guests are offered the review page; a comment the guest agreed to can go on the website', function () {
    config(['hotel.feedback.review_url' => 'https://g.page/r/example/review']);
    ($this->checkOut)();
    $data = $this->postJson("/api/v1/guest/stays/{$this->token}/feedback", ['overall' => 5, 'comment' => 'Best stay in Abuja.', 'may_quote' => true])->assertCreated()->json('data');
    expect($data['review_url'])->toBe('https://g.page/r/example/review');
    $this->get(route('stay.feedback', $this->token))->assertSee('Write a public review');

    $f = GuestFeedback::sole();
    $this->actingAs($this->gm)->post(route('feedback.publish', $f))->assertSessionHas('success');
    $item = WebsiteItem::where('kind', 'review')->latest('id')->first();
    expect($item->summary)->toBe('Best stay in Abuja.')->and($item->rating)->toBe(5)->and($f->fresh()->website_item_id)->toBe($item->id)
        ->and($item->title)->toStartWith($this->r->guest->first_name);

    // Without the guest's agreement: refused.
    $f->forceFill(['may_quote' => false, 'website_item_id' => null])->save();
    $this->actingAs($this->gm)->post(route('feedback.publish', $f))->assertSessionHas('error');
});

test('guests who checked out a few hours ago get the survey link once, by e-mail', function () {
    config(['hotel.feedback.invite_after_hours' => 3]);
    ($this->checkOut)(now()->subHour());
    $svc = app(FeedbackService::class);

    expect($svc->sendInvites())->toBe(0);
    $this->r->forceFill(['checked_out_at' => now()->subHours(4)])->save();
    expect($svc->sendInvites())->toBe(1)->and($svc->sendInvites())->toBe(0);
    expect($this->r->fresh()->feedback_invited_at)->not->toBeNull();
    $html = view('booking.emails.feedback', ['r' => $this->r->fresh('guest'), 'url' => route('stay.feedback', $this->token)])->render();
    expect($html)->toContain('Rate my stay')->toContain(route('stay.feedback', $this->token) . '?overall=5');

    $this->artisan('feedback:invite')->assertSuccessful();
});

test('the summary counts scores, the promoter score and the response rate', function () {
    $svc = app(FeedbackService::class);
    $rs = Reservation::with('guest')->limit(4)->get();
    foreach ([[5, 10], [4, 9], [3, 7], [1, 2]] as $i => [$stars, $rec]) {
        $rs[$i]->forceFill(['status' => ReservationStatus::CheckedOut, 'checked_out_at' => now()->subDay(), 'check_out_date' => today()->subDay()])->save();
        $svc->submit($rs[$i]->fresh(), ['overall' => $stars, 'recommend' => $rec, 'ratings' => ['staff' => $stars]]);
    }
    $s = $svc->summary(today()->subDays(6), today());
    expect($s['count'])->toBe(4)->and($s['average'])->toBe(3.3)->and($s['nps'])->toBe(25)   // 2 promoters, 1 detractor of 4
        ->and($s['low'])->toBe(1)->and($s['open'])->toBe(1)->and($s['stars'][5])->toBe(1)->and($s['aspects']['staff']['average'])->toBe(3.3)
        ->and($s['response'])->toBe(100);

    $this->actingAs($this->gm)->get(route('feedback.index'))->assertOk()->assertSee('3.3 / 5');
});
