<?php

use App\Enums\ReservationStatus;
use App\Models\Channels\IcalFeed;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Channels\ChannelInventory;
use App\Services\Channels\ChannelManager;
use App\Services\Channels\IcalService;
use App\Services\Reservations\AvailabilityService;
use App\Services\Reservations\ReservationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    $this->withoutDefer();
    $this->exe = RoomType::where('code', 'EXE')->first();
    $this->in = today()->addDays(10);
    $this->out = today()->addDays(13);
    $this->exeRooms = app(AvailabilityService::class)->availableForType($this->exe->id, $this->in, $this->out);
});

test('rooms left are counted night by night, and runs group equal nights', function () {
    $guest = Guest::create(['first_name' => 'Tunde', 'last_name' => 'Bello']);
    app(ReservationService::class)->create($guest, ['check_in_date' => today()->addDays(11)->toDateString(), 'check_out_date' => today()->addDays(12)->toDateString(), 'status' => 'confirmed'],
        [['room_type_id' => $this->exe->id, 'adults' => 1]]);

    $inv = app(ChannelInventory::class);
    $nights = $inv->availability($this->in, $this->out, [$this->exe->id])[$this->exe->id];
    expect(array_values($nights))->toBe([$this->exeRooms, $this->exeRooms - 1, $this->exeRooms])
        ->and($inv->runs(['2026-11-01' => 2, '2026-11-02' => 2, '2026-11-03' => 1]))->toBe([['2026-11-01', '2026-11-02', 2], ['2026-11-03', '2026-11-03', 1]]);
});

test('iCal: our calendar shows booked nights; theirs brings bookings in, moves and cancels them', function () {
    $room = Room::active()->where('room_type_id', $this->exe->id)->first();
    $feed = IcalFeed::create(['room_id' => $room->id, 'name' => 'Airbnb', 'import_url' => 'https://www.airbnb.test/calendar/ical/1.ics']);

    $ics = fn (string $start, string $end) => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:{$start}\r\nDTEND;VALUE=DATE:{$end}\r\nUID:abc123@airbnb.com\r\nSUMMARY:Reserved\r\nDESCRIPTION:Reservation URL: https://www.airbnb.com/hosting/reservations/details/HM\r\n ABCD\\nPhone Number (Last 4 Digits): 1234\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:{$start}\r\nDTEND;VALUE=DATE:{$end}\r\nUID:blk@airbnb.com\r\nSUMMARY:Airbnb (Not available)\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $d = fn (int $days) => today()->addDays($days)->format('Ymd');

    Http::fake(['www.airbnb.test/*' => Http::sequence()
        ->push($ics($d(20), $d(23)))
        ->push($ics($d(21), $d(24)))
        ->push("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n")]);
    $ical = app(IcalService::class);

    expect($ical->import($feed))->toBe(1);
    $r = Reservation::where('channel_booking_id', $feed->id . ':abc123@airbnb.com')->first();
    expect($r->channel)->toBe('Airbnb')->and($r->rooms->first()->room_id)->toBe($room->id)->and($r->check_in_date->toDateString())->toBe(today()->addDays(20)->toDateString())
        ->and($r->notes)->toContain('HMABCD');

    $export = $ical->export($feed->fresh('room'));
    expect($export)->toContain('BEGIN:VCALENDAR')->toContain('DTSTART;VALUE=DATE:' . $d(20))->toContain('DTEND;VALUE=DATE:' . $d(23))->toContain('SUMMARY:Not available')
        ->not->toContain('Airbnb guest');
    $this->get(route('channels.ical.export', $feed->export_token))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

    expect($ical->import($feed))->toBe(1);   // moved a night later
    expect($r->fresh()->check_in_date->toDateString())->toBe(today()->addDays(21)->toDateString());

    expect($ical->import($feed))->toBe(1);   // gone from their calendar: cancelled
    expect($r->fresh()->status)->toBe(ReservationStatus::Cancelled);
});

test('the room calendars page shows each tab, and calendars can be added', function () {
    $room = Room::active()->first();
    $this->post(route('channels.ical.store'), ['room_id' => $room->id, 'name' => 'Airbnb'])->assertRedirect();
    expect(IcalFeed::where('room_id', $room->id)->where('name', 'Airbnb')->exists())->toBeTrue();
    foreach (['calendars', 'bookings', 'log'] as $tab) {
        $this->get(route('channels.index', ['tab' => $tab]))->assertOk()->assertSee('Room Calendars');
    }
    expect(app(ChannelManager::class)->run(true)['errors'])->toBe([]);   // no import address: nothing to read
});
