<?php

namespace App\Services\Events;

use App\Exceptions\BookingException;
use App\Models\Events\Event;
use App\Models\Events\EventItem;
use App\Models\Events\Hall;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Halls on the day: the client is handed the hall, and gives it back. Giving it back records how many came and the
 * state the hall was left in; time past the booked end and any damage go on the event's bill. The usage report shows
 * how much each hall was booked and used, and what it earned.
 */
class HallUsageService
{
    /** Minutes past the booked end before overtime is charged. */
    public const OVERTIME_GRACE_MINUTES = 15;

    public const CONDITIONS = ['good' => 'Good', 'minor' => 'Needs cleaning / small issues', 'damaged' => 'Damaged'];

    public function __construct(private EventService $events)
    {
    }

    public function checkIn(Event $event, ?string $notes = null): void
    {
        if ($event->status !== 'confirmed') {
            throw new BookingException('Only a confirmed event can be given the hall.');
        }
        if ($event->checked_in_at) {
            throw new BookingException('The hall was handed over at ' . $event->checked_in_at->format('g:i A, D j M') . '.');
        }
        if ($event->starts_at->copy()->subDay()->isFuture()) {
            throw new BookingException('This event starts ' . $event->starts_at->format('D j M, g:i A') . '. Hand over the hall on the day.');
        }
        $event->forceFill(['checked_in_at' => now(), 'checked_in_by' => auth()->id(), 'usage_notes' => $notes ?: $event->usage_notes])->save();
        Audit::record('events.checked_in', "Hall {$event->hall->name} handed over for event {$event->number}: {$event->title}", $event);
    }

    /** Minutes the event has run (or ran) past its booked end, beyond the grace time. */
    public function overtimeMinutes(Event $event, ?Carbon $at = null): int
    {
        $at ??= $event->checked_out_at ?? now();
        $over = (int) $event->ends_at->diffInMinutes($at, false);

        return $over > self::OVERTIME_GRACE_MINUTES ? $over : 0;
    }

    /** Overtime hours (rounded up) at the hall's hourly rate. */
    public function overtimeCharge(Event $event, ?Carbon $at = null): array
    {
        $hours = (int) ceil($this->overtimeMinutes($event, $at) / 60);

        return ['hours' => $hours, 'rate' => (float) $event->hall->hourly_rate, 'amount' => round($hours * (float) $event->hall->hourly_rate, 2)];
    }

    /**
     * data: guests_actual, hall_condition, usage_notes, charge_overtime (bool),
     * damages: [{description, amount}]
     */
    public function checkOut(Event $event, array $data): void
    {
        if (! $event->checked_in_at) {
            throw new BookingException('The hall hasn’t been handed over for this event yet.');
        }
        if ($event->checked_out_at) {
            throw new BookingException('The hall was given back at ' . $event->checked_out_at->format('g:i A, D j M') . '.');
        }
        $damages = array_values(array_filter($data['damages'] ?? [], fn ($d) => filled($d['description'] ?? null) && (float) ($d['amount'] ?? 0) > 0));
        if (($data['hall_condition'] ?? 'good') === 'damaged' && ! $damages && blank($data['usage_notes'] ?? null)) {
            throw new BookingException('Say what was damaged: add it as a charge, or write it in the notes.');
        }

        DB::transaction(function () use ($event, $data, $damages) {
            $now = now();
            $ot = $this->overtimeCharge($event, $now);
            if (! empty($data['charge_overtime']) && $ot['amount'] > 0) {
                $this->events->addItem($event, ['kind' => 'overtime', 'description' => "Overtime: {$ot['hours']} h past " . $event->ends_at->format('g:i A'), 'quantity' => $ot['hours'], 'unit_price' => $ot['rate']]);
            }
            foreach ($damages as $d) {
                $this->events->addItem($event, ['kind' => 'damage', 'description' => mb_substr($d['description'], 0, 150), 'quantity' => 1, 'unit_price' => round((float) $d['amount'], 2)]);
            }
            $event->forceFill([
                'checked_out_at' => $now, 'checked_out_by' => auth()->id(),
                'guests_actual' => isset($data['guests_actual']) && $data['guests_actual'] !== '' ? (int) $data['guests_actual'] : null,
                'hall_condition' => $data['hall_condition'] ?? 'good',
                'usage_notes' => $data['usage_notes'] ?? $event->usage_notes,
            ])->save();
            Audit::record('events.checked_out', "Hall {$event->hall->name} given back after event {$event->number}: " . (self::CONDITIONS[$event->hall_condition] ?? $event->hall_condition)
                . ($event->guests_actual !== null ? ", {$event->guests_actual} came" : ''), $event, [], [], null, $event->hall_condition === 'damaged');

            // Damage is a job for maintenance.
            if ($event->hall_condition === 'damaged') {
                app(\App\Services\Mobile\StaffAlerts::class)->notify('Manage events', [
                    'title' => "Hall damaged: {$event->hall->name}",
                    'body'  => $event->title . ($event->usage_notes ? ' · ' . $event->usage_notes : ''),
                    'url'   => route('events.show', $event), 'thread' => "event:{$event->id}", 'level' => 'high',
                ]);
            }
        });
    }

    /** Per hall, for the days from–to: how much it was booked and used, who came, and what it earned. */
    public function report(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $days = (int) $from->diffInDays($to->copy()->startOfDay()) + 1;
        $openHours = (int) config('hotel.events.hall_hours_per_day', 14);
        $events = Event::with(['items', 'ticketOrders' => fn ($q) => $q->where('status', 'paid')])
            ->whereIn('status', ['confirmed', 'completed'])->overlapping($from, $to)->get()->groupBy('hall_id');
        $clip = fn ($a, $b) => $a && $b ? max(0, (min($b->timestamp, $to->timestamp) - max($a->timestamp, $from->timestamp)) / 3600) : 0;

        $rows = Hall::ordered()->get()->map(function (Hall $h) use ($events, $days, $openHours, $clip) {
            $list = $events->get($h->id, collect());
            $booked = round($list->sum(fn ($e) => $clip($e->starts_at, $e->ends_at)), 1);
            $used = round($list->sum(fn ($e) => $clip($e->checked_in_at, $e->checked_out_at)), 1);
            $withCount = $list->whereNotNull('guests_actual');

            return [
                'hall' => $h,
                'events' => $list->count(),
                'booked_hours' => $booked,
                'used_hours' => $used,
                'utilisation' => $days * $openHours ? min(100, round($booked / ($days * $openHours) * 100, 1)) : 0,
                'guests_expected' => (int) $withCount->sum('guests_expected'),
                'guests_actual' => (int) $withCount->sum('guests_actual'),
                'overtime' => round($list->sum(fn ($e) => $e->items->where('kind', 'overtime')->sum('amount')), 2),
                'damages' => round($list->sum(fn ($e) => $e->items->where('kind', 'damage')->sum('amount')), 2),
                'damaged' => $list->where('hall_condition', 'damaged')->count(),
                'revenue' => round((float) $list->sum('total'), 2),
                'tickets' => round($list->sum(fn ($e) => $e->ticketOrders->sum('total')), 2),
            ];
        })->filter(fn ($r) => $r['hall']->is_active || $r['events'] > 0)->values();

        return [
            'from' => $from, 'to' => $to, 'days' => $days, 'open_hours' => $openHours, 'rows' => $rows,
            'totals' => collect(['events', 'booked_hours', 'used_hours', 'guests_expected', 'guests_actual', 'overtime', 'damages', 'damaged', 'revenue', 'tickets'])
                ->mapWithKeys(fn ($k) => [$k => round($rows->sum($k), 2)])->all(),
        ];
    }

    /** Events on today, or still in the hall, for the day board. */
    public function today(): \Illuminate\Support\Collection
    {
        return Event::with('hall')->where('status', 'confirmed')
            ->where(fn ($q) => $q->overlapping(today(), today()->endOfDay())->orWhere(fn ($w) => $w->whereNotNull('checked_in_at')->whereNull('checked_out_at')))
            ->orderBy('starts_at')->get();
    }
}
