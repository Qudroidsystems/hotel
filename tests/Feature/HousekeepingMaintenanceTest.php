<?php

use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Reservations\ReservationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
    $this->cleaner = $make('Bisi Cleaner', 'Housekeeping');
    $this->other = $make('Tolu Cleaner', 'Housekeeping');
    $this->supervisor = $make('Hauwa Supervisor', 'Housekeeping Supervisor');
    $this->tech = $make('Emeka Tech', 'Maintenance Technician');
    $this->gm = $make('Gbenga Manager', 'General Manager');
});

test('check-out creates a cleaning task, and the room is sold again only after inspection', function () {
    $this->actingAs($this->gm);
    $res = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('rooms.room')->first();
    app(ReservationService::class)->checkOut($res, 'Settling later');
    $room = $res->rooms->first()->room->fresh();
    expect($room->status)->toBe(RoomStatus::Dirty);

    $task = HousekeepingTask::open()->where('room_id', $room->id)->firstOrFail();
    expect($task->type)->toBe('checkout')->and($task->reservation_id)->toBe($res->id);

    $this->actingAs($this->cleaner)->post(route('housekeeping.start', $task))->assertSessionHas('success');
    expect($room->fresh()->status)->toBe(RoomStatus::Cleaning);
    $this->post(route('housekeeping.finish', $task))->assertSessionHas('success');
    expect($task->fresh()->status)->toBe(HousekeepingTask::DONE)
        ->and($room->fresh()->status)->toBe(RoomStatus::Cleaning);

    // A cleaner can't pass their own room.
    $this->post(route('housekeeping.inspect', $task), ['result' => 'pass'])->assertForbidden();

    // Failed inspection sends it back with the note.
    $this->actingAs($this->supervisor)->post(route('housekeeping.inspect', $task), ['result' => 'fail', 'note' => 'Bathroom mirror smudged'])->assertSessionHas('success');
    expect($task->fresh()->status)->toBe(HousekeepingTask::PENDING)
        ->and($task->fresh()->failed_inspections)->toBe(1)
        ->and($room->fresh()->status)->toBe(RoomStatus::Dirty)
        ->and(HousekeepingTask::open()->where('room_id', $room->id)->count())->toBe(1);

    $this->actingAs($this->cleaner)->post(route('housekeeping.start', $task));
    $this->post(route('housekeeping.finish', $task));
    $this->actingAs($this->supervisor)->post(route('housekeeping.inspect', $task), ['result' => 'pass'])->assertSessionHas('success');
    expect($task->fresh()->status)->toBe(HousekeepingTask::PASSED)
        ->and($room->fresh()->status)->toBe(RoomStatus::Available);
});

test('cleaners only work on their own or unassigned rooms', function () {
    $room = Room::active()->where('status', RoomStatus::Available->value)->first();
    $room->changeStatus(RoomStatus::Dirty, 'test', $this->gm);
    $task = HousekeepingTask::open()->where('room_id', $room->id)->first();

    $this->actingAs($this->supervisor)->post(route('housekeeping.assign', $task), ['assigned_to' => $this->other->id]);
    $this->actingAs($this->cleaner)->post(route('housekeeping.start', $task))->assertForbidden();
    $this->actingAs($this->cleaner)->get(route('housekeeping.index'))->assertOk()->assertDontSee('hk-room">' . $room->number . '<', false);
    $this->actingAs($this->other)->get(route('housekeeping.index'))->assertOk()->assertSee('hk-room">' . $room->number . '<', false);
});

test('setting a room available by hand closes its cleaning task', function () {
    $room = Room::active()->where('status', RoomStatus::Available->value)->first();
    $room->changeStatus(RoomStatus::Dirty, 'test', $this->gm);
    $room->changeStatus(RoomStatus::Available, 'Checked by hand', $this->gm);

    expect(HousekeepingTask::open()->where('room_id', $room->id)->exists())->toBeFalse();
});

test('a blocking work order takes the room off sale, and finishing it sends the room to cleaning', function () {
    Storage::fake('public');
    $room = Room::active()->where('status', RoomStatus::Available->value)->first();

    $this->actingAs($this->cleaner)->post(route('maintenance.store'), [
        'room_id' => $room->id, 'category' => 'air_conditioning', 'priority' => 'high', 'title' => 'AC not cooling',
        'blocks_room' => 1, 'photos' => [UploadedFile::fake()->image('ac.jpg')],
    ])->assertRedirect();
    $order = MaintenanceOrder::latest('id')->first();
    expect($order->number)->toStartWith('WO-')
        ->and($order->due_at->diffInHours(now(), true))->toBeGreaterThan(23)
        ->and($room->fresh()->status)->toBe(RoomStatus::Maintenance)
        ->and($order->photos)->toHaveCount(1);
    Storage::disk('public')->assertExists($order->photos[0]['path']);

    // The technician can only work on jobs assigned to them.
    $this->actingAs($this->tech)->post(route('maintenance.start', $order))->assertForbidden();
    $this->actingAs($this->gm)->post(route('maintenance.assign', $order), ['assigned_to' => $this->tech->id]);
    $this->actingAs($this->tech)->post(route('maintenance.start', $order))->assertSessionHas('success');
    $this->post(route('maintenance.complete', $order), ['completion_note' => 'Regassed the unit', 'cost' => 35000])->assertSessionHas('success');

    expect($order->fresh()->status)->toBe(MaintenanceOrder::COMPLETED)
        ->and((float) $order->fresh()->cost)->toBe(35000.0)
        ->and($room->fresh()->status)->toBe(RoomStatus::Dirty)
        ->and(HousekeepingTask::open()->where('room_id', $room->id)->exists())->toBeTrue();
});

test('a room with an open blocking job goes to maintenance, not cleaning, when the guest leaves', function () {
    $this->actingAs($this->gm);
    $res = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('rooms.room')->first();
    $room = $res->rooms->first()->room;

    $this->post(route('maintenance.store'), ['room_id' => $room->id, 'category' => 'plumbing', 'priority' => 'normal', 'title' => 'Shower drains slowly', 'blocks_room' => 1]);
    expect($room->fresh()->status)->toBe(RoomStatus::Occupied);

    app(ReservationService::class)->checkOut($res, 'Settling later');
    expect($room->fresh()->status)->toBe(RoomStatus::Maintenance)
        ->and(HousekeepingTask::open()->where('room_id', $room->id)->exists())->toBeFalse();
});

test('pages load for each role', function () {
    $room = Room::active()->first();
    $this->actingAs($this->gm)->post(route('maintenance.store'), ['location' => 'Kitchen', 'category' => 'electrical', 'priority' => 'urgent', 'title' => 'Socket sparking']);
    $order = MaintenanceOrder::latest('id')->first();

    $this->get(route('housekeeping.index'))->assertOk()->assertSee('Awaiting inspection');
    $this->get(route('maintenance.index'))->assertOk()->assertSee('Socket sparking');
    $this->get(route('maintenance.show', $order))->assertOk()->assertSee('Mark as fixed');
    $this->get(route('rooms.show', $room))->assertOk();
    $this->actingAs($this->cleaner)->get(route('housekeeping.index'))->assertOk()->assertSee('My Rooms to Clean');
    $this->actingAs($this->tech)->get(route('maintenance.show', $order))->assertOk()->assertDontSee('Mark as fixed');
});
