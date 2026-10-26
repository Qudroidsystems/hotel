<?php

namespace App\Services\Channels;

use App\Enums\BookingSource;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Channels\ChannelBooking;
use App\Models\Channels\ChannelSyncLog;
use App\Models\Channels\IcalFeed;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Services\Mobile\StaffAlerts;
use App\Services\Reservations\AvailabilityService;
use App\Services\Reservations\ReservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * iCal calendars, for sites that only sync that way (Airbnb, Vrbo, smaller agencies). One calendar per room:
 * ours tells the site which nights the room is taken; theirs brings their bookings in as reservations on
 * that room. iCal carries dates only (no guest names or prices), and sites read it every few hours, so
 * reservations made at the desk show up on the site within a few hours.
 */
class IcalService
{
    public function __construct(private ReservationService $reservations, private AvailabilityService $availability, private ChannelInventory $inventory)
    {
    }

    // ── Out ───────────────────────────────────────────────────────────

    /** Our calendar for the feed's room: nights it is booked, and nights its room type is sold out. */
    public function export(IcalFeed $feed): string
    {
        $room = $feed->room;
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'hotel.local';
        $busy = [];

        $lines = ReservationRoom::with('reservation')->where('room_id', $room->id)
            ->whereHas('reservation', fn ($q) => $q->open()->where('check_out_date', '>', today()->toDateString()))->get();
        foreach ($lines as $l) {
            foreach (Reservation::nightsBetween($l->reservation->check_in_date->copy()->max(today()), $l->reservation->check_out_date) as $n) {
                $busy[$n] = true;
            }
        }
        // Whole room type sold out (bookings not yet given a room still take one of these).
        $to = today()->addDays(365);
        foreach ($this->inventory->availability(today(), $to, [$room->room_type_id])[$room->room_type_id] ?? [] as $night => $left) {
            if ($left <= 0 || ! $room->is_active) {
                $busy[$night] = true;
            }
        }
        ksort($busy);

        $out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Qudroid Systems//Smart Hotel//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escape(config('hotel.name') . ' · Room ' . $room->number)];
        $stamp = now()->utc()->format('Ymd\THis\Z');
        foreach ($this->inventory->runs($busy) as [$start, $end]) {
            $out[] = 'BEGIN:VEVENT';
            $out[] = "UID:{$room->id}-{$start}@{$host}";
            $out[] = "DTSTAMP:{$stamp}";
            $out[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $start);
            $out[] = 'DTEND;VALUE=DATE:' . Carbon::parse($end)->addDay()->format('Ymd');
            $out[] = 'SUMMARY:Not available';
            $out[] = 'END:VEVENT';
        }
        $out[] = 'END:VCALENDAR';

        return implode("\r\n", $out) . "\r\n";
    }

    // ── In ────────────────────────────────────────────────────────────

    /** Read the site's calendar and book in, move or cancel its bookings. Answers how many changed. */
    public function import(IcalFeed $feed): int
    {
        if (! $feed->import_url) {
            return 0;
        }
        try {
            $res = Http::timeout(25)->connectTimeout(10)->withHeaders(['Accept' => 'text/calendar'])->get($feed->import_url);
            if ($res->failed()) {
                throw new ChannelException("The calendar address answered HTTP {$res->status()}.");
            }
            $events = $this->parse($res->body());
            if (! str_contains($res->body(), 'BEGIN:VCALENDAR')) {
                throw new ChannelException('That address isn’t an iCal calendar.');
            }
        } catch (Throwable $e) {
            $feed->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250), 'last_synced_at' => now()])->save();
            ChannelSyncLog::write('ical', 'ical', false, 0, "{$feed->name} · room {$feed->room?->number}: " . $e->getMessage());
            throw $e instanceof ChannelException ? $e : new ChannelException($e->getMessage(), 0, $e);
        }

        $changed = 0;
        $seen = [];
        foreach ($events as $e) {
            if ($e['end']->lte(today()) || $this->isBlock($e)) {
                continue;
            }
            $id = $feed->id . ':' . $e['uid'];
            $seen[] = $id;
            $changed += $this->upsert($feed, $id, $e) ? 1 : 0;
        }

        // Bookings that left the calendar were cancelled on the site.
        $gone = Reservation::where('channel_booking_id', 'like', $feed->id . ':%')->whereNotIn('channel_booking_id', $seen ?: ['-'])
            ->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value])
            ->where('check_in_date', '>=', today()->toDateString())->get();
        foreach ($gone as $r) {
            $this->reservations->cancel($r, "Removed from the {$feed->name} calendar.");
            ChannelBooking::create(['provider' => 'ical', 'external_id' => $r->channel_booking_id, 'ota_name' => $feed->name, 'status' => 'cancelled',
                'reservation_id' => $r->id, 'ical_feed_id' => $feed->id, 'result' => 'cancelled', 'received_at' => now(), 'processed_at' => now()]);
            $this->tell("{$feed->name} cancellation: room {$feed->room->number}", $r, 'high');
            $changed++;
        }

        $feed->forceFill(['last_error' => null, 'last_synced_at' => now()])->save();
        if ($changed) {
            ChannelSyncLog::write('ical', 'ical', true, $changed, "{$feed->name} · room {$feed->room->number}: {$changed} change(s).");
        }

        return $changed;
    }

    /** Events in a calendar: uid, start, end (check-out), summary, description. */
    public function parse(string $ics): array
    {
        // Unfold continued lines (a line starting with a space or tab carries on the one before).
        $text = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
        $events = [];
        foreach (preg_split('/BEGIN:VEVENT/', $text) as $i => $chunk) {
            if ($i === 0 || ! str_contains($chunk, 'END:VEVENT')) {
                continue;
            }
            $chunk = strstr($chunk, 'END:VEVENT', true);
            $prop = function (string $name) use ($chunk): ?string {
                return preg_match('/^' . $name . '(?:;[^:\n]*)?:(.*)$/m', $chunk, $m) ? trim($m[1]) : null;
            };
            $start = $this->date($prop('DTSTART'));
            $end = $this->date($prop('DTEND')) ?? $start?->copy()->addDay();
            if (! $start || ! $end || $end->lte($start)) {
                continue;
            }
            $events[] = [
                'uid' => mb_substr($prop('UID') ?: md5($start->toDateString() . $prop('SUMMARY')), 0, 150),
                'start' => $start, 'end' => $end,
                'summary' => $this->unescape($prop('SUMMARY') ?? ''),
                'description' => $this->unescape($prop('DESCRIPTION') ?? ''),
            ];
        }

        return $events;
    }

    // ------------------------------------------------------------------

    /**
     * Nights the host closed (or that came from another calendar, like ours), not bookings. Airbnb shows
     * these as "Airbnb (Not available)"; booked stays are "Reserved".
     */
    private function isBlock(array $e): bool
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'hotel.local';

        return str_ends_with($e['uid'], '@' . $host) || preg_match('/not available|blocked|unavailable|closed/i', $e['summary']) === 1;
    }

    private function upsert(IcalFeed $feed, string $id, array $e): bool
    {
        $r = Reservation::where('channel_booking_id', $id)->first();
        if ($r && $r->check_in_date->eq($e['start']) && $r->check_out_date->eq($e['end'])) {
            return false;   // nothing new
        }
        $booking = ChannelBooking::create(['provider' => 'ical', 'external_id' => $id, 'ota_name' => $feed->name, 'status' => $r ? 'modified' : 'new',
            'ical_feed_id' => $feed->id, 'payload' => ['uid' => $e['uid'], 'start' => $e['start']->toDateString(), 'end' => $e['end']->toDateString(), 'summary' => $e['summary']],
            'received_at' => now()]);
        $room = $feed->room;
        try {
            if ($r) {
                if (! in_array($r->status, [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
                    throw new BookingException("{$feed->name} moved this booking, but it is {$r->status->label()} here.");
                }
                $this->reservations->changeDates($r, $e['start'], $e['end'], "Changed on {$feed->name}");
                $booking->forceFill(['result' => 'updated', 'reservation_id' => $r->id, 'processed_at' => now()])->save();
                $this->tell("{$feed->name} booking moved: room {$room->number}", $r->fresh(), 'normal');

                return true;
            }
            $free = $this->availability->roomIsFree($room, $e['start'], $e['end']);
            $r = DB::transaction(function () use ($feed, $id, $e, $room, $free) {
                $guest = Guest::firstOrCreate(['first_name' => mb_substr($feed->name, 0, 60), 'last_name' => 'Guest'], ['notes' => "Stays booked on {$feed->name}. The calendar doesn’t share guests’ names."]);
                $r = $this->reservations->create($guest, [
                    'check_in_date' => $e['start']->toDateString(), 'check_out_date' => $e['end']->toDateString(),
                    'source' => BookingSource::Ota->value, 'status' => ReservationStatus::Confirmed->value,
                    'notes' => "Booked on {$feed->name} (calendar). " . trim(str($e['description'])->limit(300)) . ' Add the guest\'s name and the price from the ' . $feed->name . ' booking.',
                    'channel' => $feed->name, 'channel_booking_id' => $id, 'from_channel' => true,
                ], [['room_type_id' => $room->room_type_id, 'room_id' => $free ? $room->id : null, 'adults' => 1, 'children' => 0]]);
                $r->log('channel', "Booked on {$feed->name}" . ($free ? " for room {$room->number}." : ", but room {$room->number} is taken: give the guest another room."));

                return $r;
            });
            $booking->forceFill(['result' => 'imported', 'reservation_id' => $r->id, 'overbooked' => ! $free, 'processed_at' => now()])->save();
            $this->tell(($free ? "New {$feed->name} booking: room {$room->number}" : "Overbooked: {$feed->name} booking for room {$room->number}"), $r, $free ? 'normal' : 'urgent');

            return true;
        } catch (Throwable $ex) {
            $booking->forceFill(['result' => 'failed', 'reservation_id' => $r?->id, 'error' => $ex->getMessage(), 'processed_at' => now()])->save();
            app(StaffAlerts::class)->notify('View reservations', ['title' => "{$feed->name} booking needs attention", 'body' => (string) str($ex->getMessage())->limit(180),
                'url' => route('channels.index'), 'thread' => "channel-booking:{$id}", 'level' => 'urgent', 'group' => 'guests']);

            return false;
        }
    }

    private function tell(string $title, Reservation $r, string $level): void
    {
        app(StaffAlerts::class)->notify('View reservations', [
            'title' => $title, 'body' => $r->check_in_date->format('D j M') . ' – ' . $r->check_out_date->format('D j M') . ' · ' . $r->reference,
            'url' => route('reservations.show', $r), 'thread' => "channel-booking:{$r->channel_booking_id}", 'level' => $level, 'group' => 'guests',
        ]);
    }

    private function date(?string $v): ?Carbon
    {
        if (! $v || ! preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m)) {
            return null;
        }

        return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
    }

    private function escape(string $s): string
    {
        return addcslashes($s, ",;\\");
    }

    private function unescape(string $s): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s);
    }
}
