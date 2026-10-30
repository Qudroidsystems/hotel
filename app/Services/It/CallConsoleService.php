<?php

namespace App\Services\It;

use App\Models\Chat\CallListen;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\GuestCall;
use App\Models\It\ItAlert;
use App\Models\It\ItCheck;
use App\Models\It\ItMonitor;
use App\Models\User;
use App\Services\Booking\ArrivalService;
use App\Services\Chat\CallService;
use App\Services\Chat\ChatService;
use App\Services\Weather\WeatherService;
use Illuminate\Support\Carbon;

/**
 * What the call console shows (IT Control Room → Console): every guest call that is ringing or on,
 * the ones nobody picked up, voice messages, the line quality of the network, alerts and the weather.
 * The room/area equipment board comes from FacilityService.
 */
class CallConsoleService
{
    public function __construct(private CallService $calls)
    {
    }

    public function feed(User $user): array
    {
        $this->calls->expire();
        $escalate = (int) config('hotel.call_console.escalate_after', 20);
        $live = GuestCall::whereIn('status', ['ringing', 'active'])
            ->with('conversation.section', 'conversation.reservation.guest', 'conversation.reservation.rooms.room', 'answeredBy:id,name', 'transferToUser:id,name', 'transferToSection:id,name', 'transferredBy:id,name')
            ->orderBy('created_at')->get();
        $mine = CallListen::where('user_id', $user->id)->whereIn('status', ['waiting', 'live'])->get()->keyBy('call_id');
        $listeners = CallListen::whereIn('call_id', $live->pluck('id'))->where('status', 'live')->with('user:id,name')->get()->groupBy('call_id');

        $recent = GuestCall::whereNotIn('status', ['ringing', 'active'])->where('created_at', '>=', now()->subDay())
            ->with('conversation.section', 'conversation.reservation.guest', 'conversation.reservation.rooms.room', 'answeredBy:id,name')
            ->latest()->limit(14)->get();

        $voice = ChatMessage::whereNotNull('audio')->where('sender', 'guest')->where('created_at', '>=', now()->subDays(3))
            ->whereHas('conversation')->with('conversation.section', 'conversation.reservation.guest', 'conversation.reservation.rooms.room')
            ->latest()->limit(12)->get();

        return [
            'now' => now()->toIso8601String(),
            'me' => $user->id,
            'can' => [
                'answer' => $user->can('Answer any guest call'),
                'listen' => $user->can('Listen to guest calls') && $this->calls->monitoringOn(),
                'equipment' => $user->can('Manage room equipment'),
            ],
            'calls' => $live->map(function (GuestCall $c) use ($escalate, $mine, $listeners, $user) {
                $ring = (int) $c->ringStartedAt()->diffInSeconds(now());

                return $this->row($c) + [
                    'ringing_for' => $c->status === 'ringing' ? $ring : null,
                    'ring_left' => $c->status === 'ringing' ? max(0, GuestCall::RING_SECONDS - $ring) : null,
                    'escalated' => $c->status === 'ringing' && $ring >= $escalate,
                    'on_for' => $c->answered_at ? (int) $c->answered_at->diffInSeconds(now()) : null,
                    'mine' => $c->answered_by === $user->id,
                    'transfer' => $c->leg > 1 ? ['to' => $c->transferToUser?->name ?? $c->transferToSection?->name, 'by' => $c->transferredBy?->name, 'note' => $c->transfer_note] : null,
                    'listeners' => ($listeners[$c->id] ?? collect())->pluck('user.name')->values(),
                    'my_listen' => isset($mine[$c->id]) ? ['id' => $mine[$c->id]->id, 'status' => $mine[$c->id]->status] : null,
                ];
            })->values(),
            'recent' => $recent->map(fn (GuestCall $c) => $this->row($c) + [
                'seconds' => $c->seconds(), 'ended' => ($c->ended_at ?? $c->updated_at)?->diffForHumans(short: true),
                'chat_url' => route('chat.index') . '?c=' . $c->conversation_id,
            ])->values(),
            'voice' => $voice->map(fn (ChatMessage $m) => [
                'id' => $m->id, 'seconds' => (int) $m->audio_seconds, 'clock' => ChatService::clock((int) $m->audio_seconds),
                'section' => $m->conversation?->section?->name, 'guest' => $m->conversation?->reservation?->guest?->full_name ?? 'Guest',
                'room' => $m->conversation?->reservation?->rooms->pluck('room.number')->filter()->implode(', ') ?: null,
                'at' => $m->created_at->diffForHumans(short: true), 'unread' => $m->read_at === null,
                'url' => route('it.console.voice', $m), 'chat_url' => route('chat.index') . '?c=' . $m->conversation_id,
            ])->values(),
            'stats' => [
                'ringing' => $live->where('status', 'ringing')->count(),
                'active' => $live->where('status', 'active')->count(),
                'missed_today' => GuestCall::where('status', 'missed')->where('direction', 'in')->where('created_at', '>=', today())->count(),
                'answered_today' => GuestCall::where('status', 'ended')->where('created_at', '>=', today())->count(),
                'alerts' => ItAlert::active()->count(),
            ],
            'alerts' => ItAlert::active()->with('monitor:id,name')->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
                ->latest('opened_at')->limit(6)->get()->map(fn ($a) => ['id' => $a->id, 'title' => $a->title ?? $a->monitor?->name, 'severity' => $a->severity, 'status' => $a->status])->values(),
        ];
    }

    private function row(GuestCall $c): array
    {
        $r = $c->conversation?->reservation;

        return [
            'uuid' => $c->uuid, 'status' => $c->status, 'direction' => $c->direction, 'leg' => (int) $c->leg,
            'section' => $c->conversation?->section?->name, 'section_icon' => $c->conversation?->section?->icon,
            'guest' => $r?->guest?->full_name ?? 'Guest', 'vip' => (bool) $r?->guest?->is_vip,
            'room' => $r?->rooms->pluck('room.number')->filter()->implode(', ') ?: null,
            'answered_by' => $c->answeredBy?->name, 'end_reason' => $c->end_reason,
            'started' => $c->created_at->format('g:i A'),
        ];
    }

    /**
     * Network strength for the console: each network monitor as signal bars (0–4) from its state and
     * response time, its last 24 response times for the trace, and an overall figure.
     */
    public function network(): array
    {
        $monitors = ItMonitor::active()->where('category', 'network')->ordered()->get();
        $traces = [];
        foreach ($monitors as $m) {
            $traces[$m->id] = ItCheck::where('monitor_id', $m->id)->latest('checked_at')->limit(24)->pluck('latency_ms')->reverse()->values();
        }
        $rows = $monitors->map(fn (ItMonitor $m) => [
            'id' => $m->id, 'name' => $m->name, 'status' => $m->status, 'latency' => $m->last_latency_ms,
            'bars' => $this->bars($m), 'trace' => $traces[$m->id],
            'checked' => $m->last_checked_at?->diffForHumans(short: true),
        ])->values();
        $known = $rows->filter(fn ($r) => $r['status'] !== 'unknown');

        // Internet lines (Starlink dishes and others) from Network, when that module is installed.
        $lines = null;
        if (class_exists(\App\Services\Network\NetworkService::class)) {
            try {
                $lines = app(\App\Services\Network\NetworkService::class)->consoleSummary();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'lines' => $lines && ! empty($lines['links']) ? $lines : null,
            'links' => $rows,
            'bars' => $known->isEmpty() ? null : (int) round($known->avg('bars')),
            'down' => $rows->where('status', 'down')->count(),
            'latency' => $known->whereNotNull('latency')->isEmpty() ? null : (int) round($known->whereNotNull('latency')->avg('latency')),
        ];
    }

    private function bars(ItMonitor $m): int
    {
        return match (true) {
            $m->status === 'down' => 0,
            $m->status === 'unknown' || $m->status === 'maintenance' => 0,
            $m->status === 'degraded' => 2,
            $m->last_latency_ms === null => 3,
            $m->last_latency_ms < 60 => 4,
            $m->last_latency_ms < 150 => 3,
            $m->last_latency_ms < 400 => 2,
            default => 1,
        };
    }

    /** The weather at the hotel: now, the next 12 hours and five days (Open-Meteo, cached by WeatherService). */
    public function weather(): array
    {
        $point = app(ArrivalService::class)->hotelPoint();
        $svc = app(WeatherService::class);
        if (! $point) {
            return ['available' => false, 'note' => 'Set the hotel’s map position (Settings → Hotels) to show the weather.'];
        }
        $w = $svc->raw($point[0], $point[1]);
        if (! $w) {
            return ['available' => false, 'note' => 'Weather not available right now.'];
        }
        $tz = $w['timezone'] ?? config('app.timezone');
        $now = Carbon::now($tz);
        $c = $w['current'] ?? [];
        $hours = [];
        foreach ($w['hourly']['time'] ?? [] as $i => $t) {
            $at = Carbon::parse($t, $tz);
            if ($at->lt($now->copy()->startOfHour()) || count($hours) >= 12) {
                continue;
            }
            $hours[] = ['label' => $at->format('ga'), 'temp' => (int) round($w['hourly']['temperature_2m'][$i] ?? 0),
                'rain' => $w['hourly']['precipitation_probability'][$i] ?? null] + WeatherService::describe((int) ($w['hourly']['weather_code'][$i] ?? 0), (bool) ($w['hourly']['is_day'][$i] ?? true));
        }
        $days = [];
        foreach (array_slice($w['daily']['time'] ?? [], 0, 5) as $i => $date) {
            $days[] = ['label' => $i === 0 ? 'Today' : Carbon::parse($date, $tz)->format('D'), 'max' => (int) round($w['daily']['temperature_2m_max'][$i] ?? 0),
                'min' => (int) round($w['daily']['temperature_2m_min'][$i] ?? 0), 'rain' => $w['daily']['precipitation_probability_max'][$i] ?? null,
                'wind' => isset($w['daily']['wind_speed_10m_max'][$i]) ? (int) round($w['daily']['wind_speed_10m_max'][$i]) : null] + WeatherService::describe((int) ($w['daily']['weather_code'][$i] ?? 0));
        }

        return [
            'available' => true, 'unit' => $svc->fahrenheit() ? '°F' : '°C', 'speed' => $svc->fahrenheit() ? 'mph' : 'km/h',
            'now' => $c ? ['temp' => (int) round($c['temperature_2m'] ?? 0), 'feels' => (int) round($c['apparent_temperature'] ?? 0),
                'humidity' => $c['relative_humidity_2m'] ?? null, 'wind' => isset($c['wind_speed_10m']) ? (int) round($c['wind_speed_10m']) : null,
                'rain_mm' => $c['precipitation'] ?? null] + WeatherService::describe((int) ($c['weather_code'] ?? 0), (bool) ($c['is_day'] ?? true)) : null,
            'hours' => $hours, 'days' => $days,
            'sunrise' => isset($w['daily']['sunrise'][0]) ? Carbon::parse($w['daily']['sunrise'][0], $tz)->format('g:i A') : null,
            'sunset' => isset($w['daily']['sunset'][0]) ? Carbon::parse($w['daily']['sunset'][0], $tz)->format('g:i A') : null,
        ];
    }

    /** The people and sections a call can be put through to, with who is free now. */
    public function targets(GuestCall $call, User $by): array
    {
        $t = $this->calls->transferTargets($call, $by);
        $busy = GuestCall::where('status', 'active')->pluck('answered_by')->filter()->flip();
        $t['people'] = collect($t['people'])->map(fn ($p) => $p + ['busy' => isset($busy[$p['id']])])->values();

        return $t;
    }
}
