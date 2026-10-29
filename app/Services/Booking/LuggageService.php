<?php

namespace App\Services\Booking;

use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Models\Booking\LuggageItem;
use App\Models\Reservation;
use App\Services\Messaging\GuestMessenger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The guest's luggage list (optional). A guest writes down what they are
 * bringing when they book, or later from their booking page or the guest app.
 * On check-out day the hotel reminds them (text message and e-mail) to go
 * through the list, and they tick each item off as they pack, so nothing is
 * left behind in the room. Front desk sees how much is still unticked when
 * checking the guest out.
 */
class LuggageService
{
    public const MAX_ITEMS = 100;
    public const MAX_QUANTITY = 99;

    public function enabled(): bool
    {
        return (bool) config('hotel.booking.luggage_list', true);
    }

    /** The guest can still change the list: from booking until they check out. */
    public function canEdit(Reservation $r): bool
    {
        return $this->enabled() && in_array($r->status, [S::Pending, S::Confirmed, S::CheckedIn], true);
    }

    /** Staying, and leaving today (or overdue) or tomorrow: time to go through the list. */
    public function checkOutSoon(Reservation $r): bool
    {
        return $r->status === S::CheckedIn && $r->check_out_date->lte(today()->addDay());
    }

    /**
     * Lines of text to items: one per line (or comma), with an optional count —
     * "2 shirts", "2x shirts", "shirts x2" — and an optional bag after a colon
     * at the start: "Backpack: laptop, charger".
     *
     * @return array<int, array{name: string, quantity: int, bag: ?string}>
     */
    public function parse(?string $text): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $bag = null;
            if (preg_match('/^\s*([^:,]{1,60}):\s*(.+)$/u', $line, $m)) {
                [$bag, $line] = [trim($m[1]), $m[2]];
            }
            foreach (explode(',', $line) as $part) {
                $part = trim(preg_replace('/^[\s\-\*\x{2022}]+/u', '', $part));
                if ($part === '') {
                    continue;
                }
                $qty = 1;
                if (preg_match('/^(\d{1,2})\s*[x×]?\s+(.+)$/iu', $part, $m) || preg_match('/^(\d{1,2})\s*[x×]\s*(.+)$/iu', $part, $m)) {
                    [$qty, $part] = [(int) $m[1], $m[2]];
                } elseif (preg_match('/^(.+?)\s*[x×]\s*(\d{1,2})$/iu', $part, $m)) {
                    [$part, $qty] = [$m[1], (int) $m[2]];
                }
                $items[] = ['name' => mb_substr(trim($part), 0, 120), 'quantity' => max(1, min(self::MAX_QUANTITY, $qty)), 'bag' => $bag ? mb_substr($bag, 0, 60) : null];
            }
        }

        return $items;
    }

    /**
     * Add items to the list.
     *
     * @param  array<int, array{name: string, quantity?: int|null, bag?: string|null}>  $items
     * @return int how many were added
     */
    public function add(Reservation $r, array $items): int
    {
        if (! $this->canEdit($r)) {
            throw new BookingException($this->enabled() ? 'The luggage list can’t be changed after check-out.' : 'The luggage list is switched off at this hotel.');
        }
        $items = array_values(array_filter($items, fn ($i) => trim((string) ($i['name'] ?? '')) !== ''));
        $have = $r->luggageItems()->count();
        if ($have + count($items) > self::MAX_ITEMS) {
            throw new BookingException('A luggage list can hold up to ' . self::MAX_ITEMS . ' items.');
        }
        $position = (int) $r->luggageItems()->max('position');
        foreach ($items as $i) {
            $r->luggageItems()->create([
                'name'     => mb_substr(trim($i['name']), 0, 120),
                'quantity' => max(1, min(self::MAX_QUANTITY, (int) ($i['quantity'] ?? 1))),
                'bag'      => filled($i['bag'] ?? null) ? mb_substr(trim($i['bag']), 0, 60) : null,
                'position' => ++$position,
            ]);
        }

        return count($items);
    }

    /** Change an item: any of name, quantity, bag, packed. */
    public function update(LuggageItem $item, array $data): LuggageItem
    {
        $r = $item->reservation;
        $packing = array_key_exists('packed', $data);
        // Ticking items off still works on check-out day after front desk has checked the guest out.
        if (! $this->canEdit($r) && ! ($packing && count($data) === 1 && $this->enabled() && $r->status === S::CheckedOut && $r->checked_out_at?->isToday())) {
            throw new BookingException('The luggage list can’t be changed after check-out.');
        }
        if (array_key_exists('name', $data) && filled($data['name'])) {
            $item->name = mb_substr(trim($data['name']), 0, 120);
        }
        if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
            $item->quantity = max(1, min(self::MAX_QUANTITY, (int) $data['quantity']));
        }
        if (array_key_exists('bag', $data)) {
            $item->bag = filled($data['bag']) ? mb_substr(trim($data['bag']), 0, 60) : null;
        }
        if ($packing) {
            $item->packed_at = $data['packed'] ? ($item->packed_at ?? now()) : null;
        }
        $item->save();

        return $item;
    }

    public function remove(LuggageItem $item): void
    {
        if (! $this->canEdit($item->reservation)) {
            throw new BookingException('The luggage list can’t be changed after check-out.');
        }
        $item->delete();
    }

    /** Untick everything (to pack again, e.g. moving on to another stop). */
    public function unpackAll(Reservation $r): int
    {
        if (! $this->canEdit($r)) {
            throw new BookingException('The luggage list can’t be changed after check-out.');
        }

        return $r->luggageItems()->whereNotNull('packed_at')->update(['packed_at' => null]);
    }

    /** The list and its state, for the booking page and the guest app. */
    public function forGuest(Reservation $r): array
    {
        $items = $r->relationLoaded('luggageItems') ? $r->luggageItems : $r->luggageItems()->get();
        $packed = $items->filter->isPacked()->count();
        $left = $items->count() - $packed;
        $soon = $this->checkOutSoon($r) && $items->isNotEmpty();
        $when = $r->check_out_date->isToday() || $r->check_out_date->isPast() ? 'today' : 'tomorrow';

        return [
            'available' => $this->enabled() && ($items->isNotEmpty() || $this->canEdit($r)),
            'can_edit'  => $this->canEdit($r),
            'count'     => $items->count(),
            'packed'    => $packed,
            'left'      => $left,
            'check_now' => $soon && $left > 0,
            'note'      => match (true) {
                ! $this->enabled() => null,
                $soon && $left === 0 => 'Everything on your list is packed. Have a safe trip!',
                $soon => "You check out {$when}" . ($when === 'today' ? ' by ' . $this->checkOutTime() : '') . ". Tick each item off as you pack so nothing is left in the room ({$left} still to pack).",
                $items->isEmpty() => 'Optional: list what you’re bringing. On check-out day we’ll remind you to go through it, so nothing gets left behind.',
                default => 'On check-out day we’ll remind you to go through this list. Tick each item off as you pack.',
            },
            'bags'  => $items->pluck('bag')->filter()->unique()->values(),
            'items' => $items->map(fn (LuggageItem $i) => [
                'id'       => $i->id,
                'name'     => $i->name,
                'quantity' => $i->quantity,
                'bag'      => $i->bag,
                'label'    => $i->label,
                'packed'   => $i->isPacked(),
            ])->values(),
        ];
    }

    /** For front desk: "3 of 12 items not ticked off yet", or null when there's no list. */
    public function staffSummary(Reservation $r): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $items = $r->relationLoaded('luggageItems') ? $r->luggageItems : $r->luggageItems()->get();
        if ($items->isEmpty()) {
            return null;
        }

        return ['count' => $items->count(), 'left' => $items->reject->isPacked()->count(), 'unpacked' => $items->reject->isPacked()->values(), 'items' => $items];
    }

    /**
     * Check-out day reminders: guests leaving today who made a list and still
     * have items to tick off get one text and one e-mail, from the set time.
     */
    public function sendReminders(?Carbon $now = null): int
    {
        $now ??= now();
        if (! $this->enabled() || ! config('hotel.booking.luggage_reminders', true) || $now->format('H:i') < (string) config('hotel.booking.luggage_reminder_time', '07:30')) {
            return 0;
        }
        $sent = 0;
        Reservation::where('status', S::CheckedIn->value)->whereDate('check_out_date', '<=', $now->toDateString())->whereNull('luggage_reminded_at')
            ->whereHas('luggageItems', fn ($q) => $q->whereNull('packed_at'))
            ->with(['guest', 'luggageItems'])->each(function (Reservation $r) use (&$sent) {
                $sent += $this->remind($r) ? 1 : 0;
            });

        return $sent;
    }

    /** Remind one guest now. Answers whether a text or an e-mail went out. */
    public function remind(Reservation $r): bool
    {
        $r->forceFill(['luggage_reminded_at' => now()])->saveQuietly();
        $left = $r->luggageItems->reject->isPacked();
        $link = route('stay.show', app(OnlineBookingService::class)->ensureToken($r));
        $ok = false;

        $text = app(GuestMessenger::class)->aboutBooking($r, 'luggage_reminder', ['items' => (string) $left->count(), 'check_out_time' => $this->checkOutTime()]);
        $ok = in_array($text?->status, ['sent', 'delivered', 'read'], true);

        if ($r->guest?->email) {
            try {
                Mail::send('booking.emails.luggage', ['r' => $r, 'left' => $left, 'url' => $link . '#luggage', 'time' => $this->checkOutTime()],
                    fn ($m) => $m->to($r->guest->email, $r->guest->full_name)->subject('Check-out today: your luggage list — ' . config('hotel.name')));
                $ok = true;
            } catch (Throwable $e) {
                Log::warning('Luggage reminder email failed: ' . $e->getMessage());
            }
        }

        return $ok;
    }

    private function checkOutTime(): string
    {
        return Carbon::createFromFormat('H:i', (string) config('hotel.booking.check_out_time', '12:00'))->format('g:i A');
    }
}
