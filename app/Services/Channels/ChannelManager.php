<?php

namespace App\Services\Channels;

use App\Models\Channels\ChannelBooking;
use App\Models\Channels\ChannelSyncLog;
use App\Models\Channels\IcalFeed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Room calendars (iCal) for sites like Airbnb and Vrbo. `channels:sync` (every five minutes) reads each
 * calendar when it is due; our own calendars are built fresh each time a site asks for them.
 */
class ChannelManager
{
    public function __construct(private IcalService $ical)
    {
    }

    /** Read the calendars that are due ([full]: all of them). */
    public function run(bool $full = false): array
    {
        $done = ['calendars' => 0, 'errors' => []];
        if (! Schema::hasTable('ical_feeds') || ! config('hotel.channels.ical.enabled', true)) {
            return $done;
        }
        $every = max(5, (int) config('hotel.channels.ical.every_minutes', 15));
        foreach (IcalFeed::with('room')->where('is_active', true)->whereNotNull('import_url')->get() as $feed) {
            if (! $full && $feed->last_synced_at && $feed->last_synced_at->gt(now()->subMinutes($every - 1))) {
                continue;
            }
            try {
                $done['calendars'] += $this->ical->import($feed);
            } catch (Throwable $e) {
                $done['errors'][] = "{$feed->name} (room {$feed->room?->number}): " . $e->getMessage();
            }
        }
        if ($done['errors']) {
            Log::warning('Calendar sync: ' . implode(' | ', $done['errors']));
        }

        return $done;
    }

    /** Headline figures for the Room Calendars page. */
    public function status(): array
    {
        return [
            'last_read'      => ChannelSyncLog::where('provider', 'ical')->where('ok', true)->latest('created_at')->first(),
            'last_error'     => ChannelSyncLog::where('ok', false)->where('created_at', '>', now()->subDay())->latest('created_at')->first(),
            'month_bookings' => ChannelBooking::where('result', 'imported')->where('received_at', '>=', today()->startOfMonth())->count(),
            'attention'      => ChannelBooking::where('result', 'failed')->where('received_at', '>', now()->subDays(14))->count()
                + ChannelBooking::where('overbooked', true)->where('received_at', '>', now()->subDays(14))->whereHas('reservation', fn ($q) => $q->open())->count(),
            'feeds'          => IcalFeed::where('is_active', true)->count(),
        ];
    }
}
