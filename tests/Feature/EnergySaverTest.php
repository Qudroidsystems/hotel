<?php

use App\Enums\ReservationStatus;
use App\Models\Energy\EnergyAction;
use App\Models\Energy\EnergySource;
use App\Models\Energy\EnergyZone;
use App\Models\Events\Event;
use App\Models\Events\Hall;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\Energy\EnergyAdvisor;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(today()->setTime(10, 0));
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    Notification::fake();
    $this->withoutDefer();
    $this->control = User::create(['name' => 'Bayo Control', 'email' => 'bayo@test.local', 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
    $this->control->assignRole('IT Operations');
    $this->control->hotels()->sync([\App\Models\Hotel::first()->id]);
    $this->energy = app(EnergyAdvisor::class);
});

/** A room with no booking in the next days. */
function emptyRoom(): Room
{
    $busy = \App\Models\ReservationRoom::whereNotNull('room_id')->whereHas('reservation', fn ($q) => $q->whereIn('status', ['pending', 'confirmed', 'checked_in'])
        ->where('check_out_date', '>=', today()->subDay())->where('check_in_date', '<=', today()->addDays(3)))->pluck('room_id');

    $busy = $busy->merge(\App\Models\HousekeepingTask::open()->pluck('room_id'))->merge(\App\Models\MaintenanceOrder::active()->pluck('room_id'))->filter();

    return Room::where('is_active', true)->whereNotIn('status', ['out_of_service', 'maintenance'])->whereNotIn('id', $busy)->orderBy('number')->firstOrFail();
}

function needOf(array $snap, Room $room): array
{
    return collect($snap['rooms'])->first(fn ($r) => $r['room']->id === $room->id);
}

test('rooms: in-house on, empty off, an arriving guest\'s room switched on shortly before they come', function () {
    $in = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('rooms')->first();
    $inRoom = Room::find($in->rooms->first()->room_id);
    $empty = emptyRoom();

    $snap = $this->energy->snapshot();
    expect(needOf($snap, $inRoom)['need'])->toBe('on')
        ->and(needOf($snap, $empty)['need'])->toBe('off')
        ->and(needOf($snap, $empty)['reason'])->toContain('Empty');

    // A guest booked into the empty room today, expected at 3 PM.
    $r = Reservation::where('status', ReservationStatus::Confirmed->value)->with('rooms')->first();
    $r->forceFill(['check_in_date' => today(), 'check_out_date' => today()->addDays(2), 'expected_arrival_time' => '15:00'])->save();
    $r->rooms->first()->update(['room_id' => $empty->id]);

    $snap = $this->energy->snapshot();
    $n = needOf($snap, $empty);
    expect($n['need'])->toBe('off')->and($n['until']->format('H:i'))->toBe('14:20')->and($n['reason'])->toContain('2:20 PM');

    $this->travelTo(today()->setTime(14, 30));
    expect(needOf($this->energy->snapshot(), $empty)['need'])->toBe('precool');

    // A room sensor says an in-house guest went out: eco mode.
    $this->travelTo(today()->setTime(16, 0));
    $inRoom->forceFill(['presence' => 'empty', 'presence_at' => now()->subHour()])->save();
    expect(needOf($this->energy->snapshot(), $inRoom)['need'])->toBe('eco');
});

test('the control room is told, ticks rooms off, and the saving is counted when the room is needed again', function () {
    $empty = emptyRoom();
    $r = $this->energy->sync();
    expect($r['new'])->toBeGreaterThan(0);
    Notification::assertSentTo($this->control, StaffAlert::class);

    $a = EnergyAction::where('key', "room:{$empty->id}:off")->firstOrFail();
    expect($a->status)->toBe('open')->and($a->watts)->toBe(1800 - 120);

    // Nothing new: no second alert, the same suggestion is kept.
    Notification::fake();
    expect($this->energy->sync()['new'])->toBe(0);
    Notification::assertNothingSent();

    $this->actingAs($this->control)->postJson(route('energy.done', $a))->assertOk();
    expect($a->fresh()->status)->toBe('done');

    // Three hours later a guest checks in to it: the off is closed with what it saved.
    $this->travelTo(now()->addHours(3));
    $res = Reservation::where('status', ReservationStatus::Confirmed->value)->with('rooms')->first();
    $res->forceFill(['status' => ReservationStatus::CheckedIn->value, 'check_in_date' => today(), 'check_out_date' => today()->addDay()])->save();
    $res->rooms->first()->update(['room_id' => $empty->id]);
    $this->energy->sync();
    $a->refresh();
    expect($a->closed_at)->not->toBeNull()->and($a->kwh_saved)->toBe(round(1.68 * 3, 2))
        ->and($this->energy->savings(today())['kwh'])->toBeGreaterThanOrEqual(5.0);
});

test('a hall is off between events and on for setting up', function () {
    $hall = Hall::create(['name' => 'Banquet Hall', 'capacity_banquet' => 200, 'full_day_rate' => 100000, 'color' => '#0f766e', 'is_active' => true]);
    $zone = EnergyZone::create(['name' => 'Banquet Hall', 'kind' => 'hall', 'hall_id' => $hall->id, 'watts' => 25000, 'low_watts' => 500]);
    Event::create(['number' => 'EV-TEST', 'title' => 'Wedding reception', 'hall_id' => $hall->id, 'status' => 'confirmed',
        'starts_at' => today()->setTime(16, 0), 'ends_at' => today()->setTime(22, 0), 'organiser_name' => 'Ada', 'subtotal' => 0, 'total' => 0]);

    $z = fn () => collect($this->energy->snapshot()['zones'])->first(fn ($x) => $x['zone']->id === $zone->id);
    expect($z()['need'])->toBe('low')->and($z()['reason'])->toContain('2:00 PM');
    $this->travelTo(today()->setTime(14, 15));
    expect($z()['need'])->toBe('on')->and($z()['reason'])->toContain('Setting up');
    $this->travelTo(today()->setTime(23, 30));
    expect($z()['need'])->toBe('low');
});

test('grid off: the smallest generator that carries the load is suggested, and a bigger one running is flagged', function () {
    EnergySource::create(['name' => 'Grid', 'kind' => 'grid', 'running' => true]);
    $small = EnergySource::create(['name' => 'Gen S', 'kind' => 'generator', 'capacity_kw' => 5]);
    $mid = EnergySource::create(['name' => 'Gen M', 'kind' => 'generator', 'capacity_kw' => 200]);
    $big = EnergySource::create(['name' => 'Gen L', 'kind' => 'generator', 'capacity_kw' => 500]);

    $p = $this->energy->snapshot()['power'];
    expect($p['state'])->toBe('grid')->and($p['line'])->toContain('Grid power is on');

    // The grid goes off (reported by the changeover switch): re-planned and the control room told at once.
    config(['services.device.key' => 'dev-key-123']);
    $this->postJson('/api/v1/energy/device', ['grid' => false, 'rooms' => [emptyRoom()->number => 'empty']], ['X-Device-Key' => 'dev-key-123'])->assertOk();
    $p = $this->energy->snapshot()['power'];
    $need = $p['peak3_kw'];
    $expected = collect([$small, $mid, $big])->first(fn ($g) => $g->capacity_kw * 0.8 >= $need);
    expect($p['state'])->toBe('dark')->and($p['urgent'])->toBeTrue()->and($p['choice']->id)->toBe($expected->id);
    expect(EnergyAction::where('key', "source:{$expected->id}:start")->where('status', 'open')->exists())->toBeTrue();
    Notification::assertSentTo($this->control, StaffAlert::class, fn ($n) => str_contains(json_encode($n->toArray($this->control)), $expected->name));

    // They start the big one instead: Energy Saver says the smaller one would do.
    $this->energy->setRunning($big, true, $this->control);
    $p = $this->energy->snapshot()['power'];
    expect($p['state'])->toBe('generator')->and($p['oversized'])->toBe($expected->id !== $big->id)
        ->and($p['plan_litres'])->toBeLessThanOrEqual($p['biggest_litres']);

    // A wrong device key is refused.
    $this->postJson('/api/v1/energy/device', ['grid' => true], ['X-Device-Key' => 'nope'])->assertUnauthorized();
});

test('pages and the staff app answer', function () {
    $this->actingAs($this->control)->get(route('energy.index'))->assertOk()->assertSee('Energy Saver');
    $this->actingAs($this->control)->get(route('energy.setup'))->assertOk();
    $this->actingAs($this->control)->post(route('energy.zones.store'), ['name' => 'Lobby', 'kind' => 'common', 'watts' => 6000, 'low_watts' => 1500, 'opens' => '06:00', 'closes' => '23:00'])->assertRedirect();
    $this->actingAs($this->control)->post(route('energy.sources.store'), ['name' => 'Gen 1', 'kind' => 'generator', 'capacity_kw' => 60])->assertRedirect();
    expect(EnergyZone::where('name', 'Lobby')->exists())->toBeTrue();

    \Laravel\Sanctum\Sanctum::actingAs($this->control, ['*']);
    $d = $this->getJson('/api/v1/energy')->assertOk()->json('data');
    expect($d)->toHaveKeys(['actions', 'counts', 'forecast', 'now_kw', 'power', 'switched_off', 'week'])
        ->and($d['power']['sources'][0])->toHaveKeys(['capacity_kw', 'fuel_litres', 'hours_left', 'id', 'kind', 'name', 'run_hours_today', 'running', 'since', 'tank_litres']);
    $id = $d['actions'][0]['id'];
    $this->postJson("/api/v1/energy/actions/{$id}/done")->assertOk()->assertJsonPath('data.status', 'done');
    $this->postJson('/api/v1/energy/grid', ['on' => false])->assertOk();
    expect(EnergySource::where('kind', 'grid')->first()->running)->toBeFalse();

    // Someone without the permission can't see it.
    $cleaner = User::create(['name' => 'Ngozi', 'email' => 'ngozi@test.local', 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
    $cleaner->assignRole('Housekeeping');
    $this->actingAs($cleaner)->get(route('energy.index'))->assertForbidden();
});

test('with the equipment board: only rooms with things really left on are flagged, by name, and Done switches them off', function () {
    $admin = User::role('Super Admin')->first();
    app(\App\Services\Facility\FacilityService::class)->addStandard($admin);
    $empty = emptyRoom();
    $other = Room::where('id', '!=', $empty->id)->whereIn('id', collect($this->energy->snapshot()['rooms'])->where('need', 'off')->map(fn ($r) => $r['room']->id))->firstOrFail();
    $dev = fn (Room $r, string $kind) => \App\Models\Facility\FacilityDevice::where('room_id', $r->id)->where('kind', $kind)->first();
    $dev($empty, 'ac')->update(['state' => 'on']);
    $dev($empty, 'lights')->update(['state' => 'on']);

    $this->energy->sync();
    $a = EnergyAction::where('key', "room:{$empty->id}:off")->firstOrFail();
    expect($a->title)->toBe("Room {$empty->number}: switch off the air conditioner and lights")
        ->and($a->watts)->toBe(1350)->and($a->devices)->toHaveCount(2)
        // An empty room whose equipment is all off needs nothing.
        ->and(EnergyAction::where('key', "room:{$other->id}:off")->exists())->toBeFalse();

    $this->energy->done($a, $this->control);
    expect($dev($empty, 'ac')->fresh()->state)->toBe('off')->and($dev($empty, 'lights')->fresh()->state)->toBe('off');

    // A generator linked to its board switch: a hub reporting it on is read as running.
    $genSwitch = \App\Models\Facility\FacilityDevice::create(['area' => 'Generator house', 'name' => 'Gen 1', 'kind' => 'generator', 'code' => 'GEN-1', 'state' => 'off', 'is_critical' => true]);
    $gen = EnergySource::create(['name' => 'Gen 1', 'kind' => 'generator', 'capacity_kw' => 100, 'device_id' => $genSwitch->id]);
    app(\App\Services\Facility\FacilityService::class)->set($genSwitch, 'on');
    $this->energy->snapshot();
    expect($gen->fresh()->running)->toBeTrue();
});
