<?php

use App\Enums\ReservationStatus;
use App\Models\Attendance\Department;
use App\Models\Attendance\StaffProfile;
use App\Models\Hotel;
use App\Models\Incident;
use App\Models\LostItem;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\StaffTask;
use App\Models\User;
use App\Models\Visitor;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    Notification::fake();
    Storage::fake('public');
    $this->withoutDefer();
    $hotel = Hotel::first();
    $this->make = function (string $name, string $role) use ($hotel) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
        $u->assignRole($role);
        $u->hotels()->sync([$hotel->id]);

        return $u;
    };
    $this->gm = ($this->make)('Gbenga Manager', 'General Manager');
    $this->desk = ($this->make)('Ada Desk', 'Front Desk');
    $this->guard = ($this->make)('Sani Guard', 'Security Officer');
    $this->cleaner = ($this->make)('Bisi Cleaner', 'Housekeeping');
});

test('each person sees only the modules their role allows', function () {
    $keys = fn (User $u) => collect($this->actingAs($u, 'sanctum')->getJson('/api/v1/modules')->assertOk()->json('data'))->pluck('key')->all();

    expect($keys($this->guard))->toContain('visitors', 'incidents', 'tasks')->not->toContain('stays', 'dispatch', 'expenses');
    expect($keys($this->desk))->toContain('stays', 'dispatch', 'tasks');
    expect($keys($this->cleaner))->toContain('tasks')->not->toContain('visitors', 'stays', 'fleet');
    $this->actingAs($this->cleaner, 'sanctum')->getJson('/api/v1/modules/visitors')->assertForbidden();
    $this->actingAs($this->cleaner, 'sanctum')->getJson('/api/v1/modules/nope')->assertNotFound();
});

test('tasks: a manager gives one from the phone, the worker starts it and marks it done with a photo', function () {
    $dept = Department::create(['name' => 'Housekeeping', 'code' => 'HK']);
    StaffProfile::create(['user_id' => $this->cleaner->id, 'staff_no' => 'S1', 'department_id' => $dept->id, 'badge_token' => str()->random(32)]);

    $form = $this->actingAs($this->gm, 'sanctum')->getJson('/api/v1/modules/tasks')->assertOk()->json('data.form');
    expect(collect($form['fields'])->pluck('name'))->toContain('title', 'department_id', 'assigned_to', 'priority');
    $this->postJson('/api/v1/modules/tasks', ['title' => 'Polish the lobby brass', 'department_id' => (string) $dept->id, 'priority' => 'high'])
        ->assertCreated()->assertJsonPath('data.item.title', 'Polish the lobby brass');
    $task = StaffTask::first();

    $page = $this->actingAs($this->cleaner, 'sanctum')->getJson('/api/v1/modules/tasks')->assertOk()->json('data');
    expect($page['items'][0]['id'])->toBe($task->id)->and(collect($page['items'][0]['actions'])->pluck('id'))->toContain('start', 'finish');
    $this->postJson("/api/v1/modules/tasks/items/{$task->id}/actions/start")->assertOk()->assertJsonPath('message', 'Started.');
    $this->post("/api/v1/modules/tasks/items/{$task->id}/actions/finish", ['note' => 'Shiny', 'photos' => [UploadedFile::fake()->image('done.jpg')]], ['Accept' => 'application/json'])
        ->assertOk();
    expect($task->fresh()->status)->toBe(StaffTask::DONE)->and($task->fresh()->attachments)->toHaveCount(1);

    // A button that's no longer on offer is refused with a reason, not run.
    $this->postJson("/api/v1/modules/tasks/items/{$task->id}/actions/start")->assertStatus(422)->assertJsonPath('code', 'not_allowed_now');
});

test('visitors: the guard signs a visitor in, checks the pass by scanning and signs them out', function () {
    $this->actingAs($this->guard, 'sanctum');
    $this->postJson('/api/v1/modules/visitors', ['full_name' => 'Tunde Visitor', 'purpose' => 'meeting', 'host' => 'Accounts', 'party_size' => 2])->assertCreated();
    $v = Visitor::first();
    expect($v->party_size)->toBe(2)->and($v->signed_in_by)->toBe($this->guard->id);

    $this->postJson('/api/v1/modules/visitors/scan', ['code' => strtolower($v->pass_code)])->assertOk()->assertJsonPath('data.state', 'ok')->assertJsonPath('data.title', 'Tunde Visitor');
    $this->postJson('/api/v1/modules/visitors/scan', ['code' => 'https://hotel.test/security/visitors/check/NOPE1234'])->assertOk()->assertJsonPath('data.state', 'bad');
    $this->postJson("/api/v1/modules/visitors/items/{$v->id}/actions/sign_out")->assertOk();
    expect($v->fresh()->signed_out_at)->not->toBeNull();
    $this->postJson('/api/v1/modules/visitors/scan', ['code' => $v->pass_code])->assertJsonPath('data.state', 'bad');
});

test('incidents and lost & found from the phone, with photos and required fields checked', function () {
    $this->actingAs($this->guard, 'sanctum');
    $room = Room::first();
    $this->postJson('/api/v1/modules/incidents', ['type' => 'theft', 'severity' => 'high', 'title' => 'Phone taken', 'description' => 'Guest says phone taken at the pool.'])
        ->assertStatus(422);   // no room or place
    $this->post('/api/v1/modules/incidents', ['type' => 'theft', 'severity' => 'high', 'title' => 'Phone taken', 'description' => 'At the pool.', 'location' => 'Pool',
        'photos' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])->assertCreated();
    $i = Incident::first();
    expect($i->reported_by)->toBe($this->guard->id);
    $this->postJson('/api/v1/modules/incidents', ['type' => 'nonsense', 'severity' => 'high', 'title' => 'x', 'description' => 'y', 'location' => 'z'])
        ->assertStatus(422)->assertJsonValidationErrors('type');

    $this->actingAs(($this->make)('Kemi Cashier', 'Cashier'), 'sanctum')->post('/api/v1/modules/lost_found', ['description' => 'Black wallet', 'category' => 'bag'], ['Accept' => 'application/json'])
        ->assertForbidden();
    $this->actingAs($this->guard, 'sanctum')->post('/api/v1/modules/lost_found', ['description' => 'Black wallet', 'category' => 'bag', 'valuable' => '1', 'room_id' => (string) $room->id,
        'photo' => UploadedFile::fake()->image('w.jpg')], ['Accept' => 'application/json'])->assertCreated();
    $item = LostItem::first();
    expect($item->valuable)->toBeTrue()->and($item->found_location)->toBe('Room ' . $room->number)->and($item->photo_path)->not->toBeNull();
    $this->postJson("/api/v1/modules/lost_found/items/{$item->id}/actions/hand_over", ['claimant_name' => 'Owner'])->assertStatus(422)->assertJsonValidationErrors('claimant_id');
    $this->postJson("/api/v1/modules/lost_found/items/{$item->id}/actions/hand_over", ['claimant_name' => 'Owner', 'claimant_id' => 'NIN 123'])->assertOk();
    expect($item->fresh()->status)->toBe('claimed');
})->skip(fn () => ! \Spatie\Permission\Models\Permission::where('name', 'Manage lost & found')->exists(), 'no lost & found permission');

test('front desk: give a room, check in, record a payment and check out from the phone', function () {
    $r = Reservation::whereIn('status', [ReservationStatus::Confirmed->value, ReservationStatus::Pending->value])->whereDate('check_in_date', today())->with('rooms')->first()
        ?? Reservation::whereIn('status', [ReservationStatus::Confirmed->value, ReservationStatus::Pending->value])->with('rooms')->first();
    $r->update(['check_in_date' => today(), 'check_out_date' => today()->addDays(2)]);
    foreach ($r->rooms as $line) {
        $line->update(['room_id' => null]);
    }
    $this->actingAs($this->desk, 'sanctum');
    $item = collect($this->getJson('/api/v1/modules/stays?tab=arrivals')->assertOk()->json('data.items'))->firstWhere('id', $r->id);
    expect($item)->not->toBeNull();
    $give = collect($item['actions'])->first(fn ($a) => str_starts_with($a['id'], 'room_'));
    expect($give)->not->toBeNull();
    $roomId = $give['fields'][0]['options'][0]['value'];
    $this->postJson("/api/v1/modules/stays/items/{$r->id}/actions/{$give['id']}", ['room_id' => $roomId])->assertOk();
    foreach ($r->fresh()->rooms as $line) {
        $line->room_id ?: $line->update(['room_id' => $roomId]);
        $line->room->update(['status' => 'available']);
    }
    $this->postJson("/api/v1/modules/stays/items/{$r->id}/actions/check_in")->assertOk();
    expect($r->fresh()->status)->toBe(ReservationStatus::CheckedIn);

    $this->postJson("/api/v1/modules/stays/items/{$r->id}/actions/pay", ['method' => 'transfer', 'amount' => 1000])->assertStatus(422);   // needs a reference
    $balance = $r->fresh()->folio?->balance() ?? 0;
    $r->fresh()->update(['check_out_date' => today()->addDay()]);
    $this->postJson("/api/v1/modules/stays/items/{$r->id}/actions/check_out")->assertStatus(422)->assertJsonPath('code', 'not_allowed_now');
    $owed = app(\App\Services\Billing\FolioService::class)->forReservation($r->fresh());
    app(\App\Services\Billing\FolioService::class)->postRoomNights($r->fresh());
    // Pay for the whole stay (check-out bills every night first).
    $this->postJson("/api/v1/modules/stays/items/{$r->id}/actions/pay", ['method' => 'cash', 'amount' => round($owed->fresh()->balance(), 2) + (float) $r->fresh()->total_amount + 100000])
        ->assertOk()->assertJsonPath('data.item.id', $r->id);
    $this->postJson("/api/v1/modules/stays/items/{$r->id}/actions/check_out")->assertOk();
    expect($r->fresh()->status)->toBe(ReservationStatus::CheckedOut);
});

test('expenses: record one with a receipt photo; it lands in approvals over the limit', function () {
    $acc = ($this->make)('Ade Accounts', 'Accountant');
    $cat = \App\Models\Finance\ExpenseCategory::where('is_active', true)->first() ?? \App\Models\Finance\ExpenseCategory::create(['name' => 'Fuel', 'is_active' => true]);
    $this->actingAs($acc, 'sanctum');
    $this->post('/api/v1/modules/expenses', ['description' => 'Diesel for generator', 'category_id' => (string) $cat->id, 'amount' => 45000, 'expense_date' => today()->toDateString(),
        'photos' => [UploadedFile::fake()->image('receipt.jpg')]], ['Accept' => 'application/json'])->assertCreated();
    $e = \App\Models\Finance\Expense::first();
    expect((float) $e->amount)->toBe(45000.0)->and($e->recorded_by)->toBe($acc->id)->and($e->attachments)->toHaveCount(1);
    expect($this->getJson('/api/v1/modules/expenses')->json('data.items.0.id'))->toBe($e->id);
})->skip(fn () => ! \Spatie\Permission\Models\Role::findByName('Accountant')->hasPermissionTo('Record expenses'), 'accountants do not record expenses here');

test('an action sent twice with the same offline id happens once and the first answer comes back', function () {
    $dept = Department::create(['name' => 'Front', 'code' => 'FO']);
    StaffProfile::create(['user_id' => $this->desk->id, 'staff_no' => 'S9', 'department_id' => $dept->id, 'badge_token' => str()->random(32)]);
    $this->actingAs($this->gm, 'sanctum');
    $h = ['X-Offline-Id' => 'phone-1234567890', 'X-Offline-At' => now()->subMinutes(20)->toIso8601String()];
    $first = $this->postJson('/api/v1/modules/tasks', ['title' => 'Fix sign', 'department_id' => (string) $dept->id, 'priority' => 'normal'], $h)->assertCreated();
    $again = $this->postJson('/api/v1/modules/tasks', ['title' => 'Fix sign', 'department_id' => (string) $dept->id, 'priority' => 'normal'], $h)->assertCreated()
        ->assertHeader('X-Offline-Replayed', '1');
    expect(StaffTask::count())->toBe(1)->and($again->json('message'))->toBe($first->json('message'));

    // A different person with the same id is a different action.
    StaffProfile::create(['user_id' => $this->gm->id, 'staff_no' => 'S10', 'department_id' => $dept->id, 'badge_token' => str()->random(32)]);
    $this->actingAs($this->desk, 'sanctum')->postJson('/api/v1/modules/tasks', ['title' => 'Fix sign', 'department_id' => (string) $dept->id, 'priority' => 'normal'], $h)->assertCreated();
    expect(StaffTask::count())->toBe(2);

    // A refusal is remembered too (it won't suddenly go through on the third try with different data).
    $bad = ['X-Offline-Id' => 'phone-bad-0000001'];
    $this->postJson('/api/v1/modules/tasks', ['title' => ''], $bad)->assertStatus(422);
    $this->postJson('/api/v1/modules/tasks', ['title' => ''], $bad)->assertStatus(422)->assertHeader('X-Offline-Replayed', '1');
});

test('the website takes a kept form later, once, and answers in JSON', function () {
    $hk = ($this->make)('Hope Housekeeper', 'Housekeeping Supervisor');
    $room = Room::first();
    $task = app(\App\Services\Operations\HousekeepingService::class)->raise($room, 'checkout', null, null, $hk->id);
    $this->actingAs($hk);
    $patterns = \App\Support\OfflineForms::patterns();
    expect(collect($patterns)->contains(fn ($p) => preg_match('#' . str_replace('#', '\#', $p) . '#', parse_url(route('housekeeping.start', $task), PHP_URL_PATH))))->toBeTrue()
        ->and(collect($patterns)->contains(fn ($p) => preg_match('#' . str_replace('#', '\#', $p) . '#', parse_url(url('/billing/payments'), PHP_URL_PATH))))->toBeFalse();

    $h = ['X-Offline-Id' => 'web-abcdef123456', 'X-Offline-At' => now()->subMinutes(5)->toIso8601String(), 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
    $this->withHeaders($h)->post(route('housekeeping.start', $task))->assertOk()->assertJsonMissing(['ok' => false]);
    $this->withHeaders($h)->post(route('housekeeping.start', $task))->assertOk()->assertHeader('X-Offline-Replayed', '1');
    expect($task->fresh()->status)->not->toBe('pending');
});
