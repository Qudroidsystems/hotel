<?php

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\EmergencyContact;
use App\Models\Incident;
use App\Models\LostItem;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Security\SecurityService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->guard = $make('Musa Guard', 'Security Officer');
    $this->desk = $make('Ada Desk', 'Front Desk');
    $this->cleaner = $make('Bisi Cleaner', 'Housekeeping');
    $this->gm = $make('Gbenga Manager', 'General Manager');
});

test('a visitor is signed in to see a guest, the pass checks out at the gate, then stops working after sign-out', function () {
    $res = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('rooms.room', 'guest')->first();

    $this->actingAs($this->desk)->get(route('visitors.index'))->assertOk()->assertSee('Sign in a visitor');
    $this->post(route('visitors.store'), [
        'full_name' => 'Chidi Okafor', 'phone' => '08030000000', 'id_type' => 'NIN', 'id_number' => '12345678901',
        'purpose' => 'guest_visit', 'reservation_id' => $res->id, 'vehicle_plate' => 'lag-123-xy', 'party_size' => 2,
    ])->assertRedirect();

    $visitor = Visitor::firstOrFail();
    expect($visitor->vehicle_plate)->toBe('LAG-123-XY')
        ->and($visitor->expected_out_at->isFuture())->toBeTrue()
        ->and($visitor->visiting)->toContain($res->guest->full_name);
    $this->get(route('visitors.pass', $visitor))->assertOk()->assertSee($visitor->pass_code)->assertSee('Chidi Okafor');

    $this->actingAs($this->guard)->get(route('visitors.check', ['code' => strtolower($visitor->pass_code)]))->assertOk()->assertSee('Valid pass');
    $this->get(route('visitors.check', ['code' => 'VNOTREAL00']))->assertSee('Not recognised');
    expect(AuditLog::where('action', 'security.pass_rejected')->where('flagged', true)->exists())->toBeTrue();

    $visitor->forceFill(['expected_out_at' => now()->subMinutes(10)])->save();
    $this->get(route('visitors.index'))->assertSee('still on site after their pass ran out');
    $this->get(route('visitors.check', ['code' => $visitor->pass_code]))->assertSee('Pass expired');

    $this->post(route('visitors.sign-out', $visitor))->assertSessionHas('success');
    $this->post(route('visitors.sign-out', $visitor))->assertSessionHas('error');
    $this->get(route('visitors.check', ['code' => $visitor->pass_code]))->assertSee('Already signed out');

    // Housekeeping can't see the visitor book.
    $this->actingAs($this->cleaner)->get(route('visitors.index'))->assertForbidden();
});

test('old visitor records lose their personal details', function () {
    $svc = app(SecurityService::class);
    $old = $svc->signIn(['full_name' => 'Old Visitor', 'phone' => '0801', 'id_number' => 'A1', 'purpose' => 'delivery', 'vehicle_plate' => 'abc'], $this->guard);
    $old->forceFill(['signed_out_at' => now()->subDays(400)])->save();
    $recent = $svc->signIn(['full_name' => 'New Visitor', 'phone' => '0802', 'purpose' => 'meeting'], $this->guard);
    $svc->signOut($recent, $this->guard);

    Artisan::call('security:prune-visitors');

    expect($old->fresh()->phone)->toBeNull()->and($old->fresh()->id_number)->toBeNull()->and($old->fresh()->anonymised_at)->not->toBeNull()
        ->and($old->fresh()->full_name)->toBe('Old Visitor')
        ->and($recent->fresh()->phone)->toBe('0802');
});

test('incidents are reported, worked through a timeline and resolved, with serious ones flagged', function () {
    Storage::fake('public');
    $room = Room::where('number', '!=', '')->first();

    $this->actingAs($this->cleaner)->get(route('incidents.index'))->assertOk();
    $this->post(route('incidents.store'), [
        'type' => 'theft', 'severity' => 'high', 'title' => 'Guest laptop missing', 'description' => 'Guest says laptop was on the desk at 2pm.',
        'room_id' => $room->id, 'photos' => [UploadedFile::fake()->image('desk.jpg')], 'assigned_to' => $this->guard->id,
    ])->assertRedirect();

    $incident = Incident::firstOrFail();
    expect($incident->number)->toBe('INC-' . str_pad((string) $incident->id, 6, '0', STR_PAD_LEFT))
        ->and($incident->assigned_to)->toBeNull()                // reporters can't assign
        ->and($incident->photos)->toHaveCount(1)
        ->and($incident->updates()->count())->toBe(1)
        ->and(AuditLog::where('action', 'security.incident_reported')->where('flagged', true)->exists())->toBeTrue();

    // The reporter can see their report but can't work it.
    $this->get(route('incidents.show', $incident))->assertOk()->assertSee('Guest laptop missing')->assertDontSee('Save update');
    $this->post(route('incidents.updates.store', $incident), ['note' => 'x'])->assertForbidden();

    $this->actingAs($this->gm)->post(route('incidents.assign', $incident), ['assigned_to' => $this->guard->id])->assertSessionHas('success');

    $this->actingAs($this->guard)->post(route('incidents.updates.store', $incident), ['note' => 'Checked CCTV for corridor.', 'status' => 'investigating', 'police_reference' => 'NPF/123'])->assertSessionHas('success');
    expect($incident->fresh()->status)->toBe('investigating')->and($incident->fresh()->police_reference)->toBe('NPF/123');

    $this->post(route('incidents.updates.store', $incident), ['note' => 'Laptop found in luggage store, returned.', 'status' => 'resolved'])->assertSessionHas('success');
    $incident->refresh();
    expect($incident->status)->toBe('resolved')->and($incident->resolution)->toContain('returned')->and($incident->resolved_by)->toBe($this->guard->id)
        ->and($incident->updates()->count())->toBe(4);

    $this->actingAs($this->gm)->post(route('incidents.updates.store', $incident), ['note' => 'Reviewed.', 'status' => 'closed'])->assertSessionHas('success');
    expect($incident->fresh()->status)->toBe('closed');
    $this->get(route('incidents.show', $incident))->assertOk()->assertSee('Timeline')->assertSee('Checked CCTV');
});

test('managers keep the emergency contact list', function () {
    $this->actingAs($this->guard)->post(route('incidents.contacts.store'), ['name' => 'Fire Service', 'role' => 'Emergency', 'phone' => '112'])->assertSessionHas('success');
    $c = EmergencyContact::firstOrFail();
    $this->get(route('incidents.index'))->assertSee('Fire Service')->assertSee('tel:112', false);
    $this->put(route('incidents.contacts.update', $c), ['name' => 'Fire Service', 'phone' => '0700FIRE'])->assertSessionHas('success');
    expect($c->fresh()->phone)->toBe('0700FIRE');

    $this->actingAs($this->desk)->post(route('incidents.contacts.store'), ['name' => 'X', 'phone' => '1'])->assertForbidden();
    $this->actingAs($this->gm)->delete(route('incidents.contacts.destroy', $c))->assertSessionHas('success');
    expect(EmergencyContact::count())->toBe(0);
});

test('a found item is logged, then handed back only once and only with an ID', function () {
    $room = Room::first();
    $this->actingAs($this->cleaner)->post(route('lost-found.store'), ['description' => 'Gold wristwatch', 'category' => 'jewellery', 'valuable' => 1, 'room_id' => $room->id, 'storage_location' => 'Front desk safe'])
        ->assertSessionHas('success');
    $item = LostItem::firstOrFail();
    expect($item->number)->toStartWith('LF-')->and($item->found_location)->toBe('Room ' . $room->number)->and($item->found_by)->toBe($this->cleaner->id);

    $this->actingAs($this->desk)->get(route('lost-found.index'))->assertOk()->assertSee('Gold wristwatch');
    $this->post(route('lost-found.hand-over', $item), ['claimant_name' => 'Mrs Bello'])->assertSessionHasErrors('claimant_id');
    $this->post(route('lost-found.hand-over', $item), ['claimant_name' => 'Mrs Bello', 'claimant_id' => 'Passport A123'])->assertSessionHas('success');
    expect($item->fresh()->status)->toBe('claimed')
        ->and(AuditLog::where('action', 'security.item_returned')->where('flagged', true)->exists())->toBeTrue();

    $this->post(route('lost-found.dispose', $item), ['disposal_note' => 'Donated'])->assertSessionHas('error');
    $this->actingAs($this->gm)->get(route('lost-found.index', ['show' => 'claimed']))->assertSee('Mrs Bello');

    // Bar staff without lost & found rights are kept out.
    $cashier = User::create(['name' => 'Cash Ier', 'email' => 'cashier@test.local', 'password' => bcrypt('Secret@123')]);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier)->get(route('lost-found.index'))->assertForbidden();
});

test('security pages show up in the menu for the security officer', function () {
    $this->actingAs($this->guard)->get(route('visitors.index'))->assertOk()
        ->assertSee(route('incidents.index'), false)->assertSee(route('lost-found.index'), false)->assertSee(route('visitors.check'), false);
    $this->get(route('visitors.check'))->assertOk()->assertSee('Scan pass');
});
