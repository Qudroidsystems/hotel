<?php

namespace App\Services\Booking;

use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Models\Booking\GuestFeedback;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Website\WebsiteItem;
use App\Services\Messaging\GuestMessenger;
use App\Services\Mobile\StaffAlerts;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Guest feedback after a stay (spec: guest satisfaction). Once a guest has
 * checked out they get a link (text and e-mail, a few hours later) to a short
 * survey on their booking page or in the app: stars for the stay overall and
 * for each part of it, "would you recommend us" from 0 to 10, and a comment.
 * One survey per booking. A low score alerts the managers so someone calls
 * the guest back; a happy guest is offered the hotel's Google / TripAdvisor
 * page, and their comment can be shown on the website if they said so.
 */
class FeedbackService
{
    public const MAX_COMMENT = 2000;

    public function __construct(private StaffAlerts $alerts)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.feedback.enabled', true);
    }

    /** Whether this booking's guest can still leave feedback (answers why not, or null). */
    public function blocker(Reservation $r): ?string
    {
        return match (true) {
            ! $this->enabled()                => 'Feedback is switched off.',
            $r->status !== S::CheckedOut      => 'You can rate your stay once you have checked out.',
            $this->closed($r)                 => 'This survey has closed.',
            $this->existing($r) !== null      => 'Thank you, we already have your feedback for this stay.',
            default                           => null,
        };
    }

    private function closed(Reservation $r): bool
    {
        $out = $r->checked_out_at ?? $r->check_out_date;

        return $out->copy()->addDays((int) config('hotel.feedback.window_days', 30))->isPast();
    }

    public function existing(Reservation $r): ?GuestFeedback
    {
        return GuestFeedback::where('reservation_id', $r->id)->first();
    }

    /** For the booking page and the guest app (key `feedback` of the stay). */
    public function forGuest(Reservation $r): array
    {
        $given = $this->existing($r);
        $blocker = $given ? null : $this->blocker($r);

        return [
            'available'  => $given !== null || $blocker === null,
            'can_rate'   => $given === null && $blocker === null,
            'given'      => $given ? ['overall' => $given->overall, 'ratings' => (object) ($given->ratings ?? []), 'recommend' => $given->recommend, 'comment' => $given->comment, 'at' => $given->created_at->toIso8601String()] : null,
            'aspects'    => collect(GuestFeedback::ASPECTS)->map(fn ($a, $k) => ['key' => $k, 'label' => $a[0]])->values()->all(),
            'review_url' => $this->reviewUrl($given),
            'note'       => $given ? 'Thank you for telling us about your stay.' : ($blocker === null ? 'How was your stay? It takes a minute and every answer is read by our managers.' : null),
        ];
    }

    /** The hotel's public review page, offered only to guests who were happy. */
    public function reviewUrl(?GuestFeedback $f): ?string
    {
        $url = config('hotel.feedback.review_url');

        return $f && $url && $f->overall >= 4 ? (string) $url : null;
    }

    /**
     * Save a guest's answers. $data: overall (1–5), ratings {aspect: 1–5},
     * recommend (0–10), comment, contact_ok, may_quote.
     */
    public function submit(Reservation $r, array $data, string $source = 'web'): GuestFeedback
    {
        if ($why = $this->blocker($r)) {
            throw new BookingException($why);
        }
        $ratings = collect($data['ratings'] ?? [])
            ->only(array_keys(GuestFeedback::ASPECTS))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => max(1, min(5, (int) $v)))
            ->all();

        $f = GuestFeedback::create([
            'reservation_id' => $r->id,
            'guest_id'       => $r->guest_id,
            'overall'        => max(1, min(5, (int) $data['overall'])),
            'ratings'        => $ratings ?: null,
            'recommend'      => isset($data['recommend']) && $data['recommend'] !== '' ? max(0, min(10, (int) $data['recommend'])) : null,
            'comment'        => filled($data['comment'] ?? null) ? mb_substr(trim($data['comment']), 0, self::MAX_COMMENT) : null,
            'contact_ok'     => ! empty($data['contact_ok']),
            'may_quote'      => ! empty($data['may_quote']),
            'source'         => in_array($source, ['web', 'app', 'desk'], true) ? $source : 'web',
        ]);

        if ($f->isLow() || ($f->nps_group === 'detractor' && $f->overall <= 3)) {
            $f->forceFill(['status' => GuestFeedback::FOLLOW_UP])->save();
            $r->loadMissing('guest');
            $this->alerts->notify('Handle guest feedback', [
                'title'  => "Low score: {$f->overall}/5 from " . ($r->guest?->full_name ?? 'a guest'),
                'body'   => $f->comment ? str($f->comment)->limit(140)->toString() : "Booking {$r->reference}. Call them back.",
                'url'    => route('feedback.show', $f), 'thread' => 'feedback', 'level' => 'high',
            ]);
        }
        Audit::record('guest.feedback', "Guest feedback for {$r->reference}: {$f->overall}/5" . ($f->recommend !== null ? ", recommend {$f->recommend}/10" : ''), $r,
            new: ['overall' => $f->overall, 'recommend' => $f->recommend, 'source' => $f->source]);

        return $f;
    }

    /** A manager notes what was done (called the guest, refund, fixed the room…). */
    public function handle(GuestFeedback $f, User $by, string $status, ?string $note): void
    {
        if (! array_key_exists($status, GuestFeedback::STATUSES)) {
            throw new BookingException('Choose a status.');
        }
        $f->forceFill([
            'status'         => $status,
            'follow_up_note' => filled($note) ? $note : $f->follow_up_note,
            'handled_by'     => $by->id,
            'handled_at'     => now(),
        ])->save();
        Audit::record('guest.feedback_handled', "Feedback for {$f->reservation?->reference} marked “{$f->status_label}”", $f->reservation, reason: $note, by: $by);
    }

    /** Show the guest's comment on the website as a review (only when they agreed). */
    public function publish(GuestFeedback $f, User $by): WebsiteItem
    {
        if (! $f->may_quote || blank($f->comment)) {
            throw new BookingException('The guest did not agree to have their comment shown, or left no comment.');
        }
        if ($f->website_item_id && ($item = WebsiteItem::find($f->website_item_id))) {
            return $item;
        }
        $g = $f->guest;
        $item = WebsiteItem::create([
            'kind'       => 'review',
            'title'      => $g ? trim($g->first_name . ' ' . mb_substr((string) $g->last_name, 0, 1) . ($g->last_name ? '.' : '')) : 'A guest',
            'summary'    => str($f->comment)->limit(600)->toString(),
            'meta'       => 'Stayed ' . ($f->reservation?->check_in_date?->format('F Y') ?? $f->created_at->format('F Y')),
            'rating'     => $f->overall,
            'is_active'  => true,
            'created_by' => $by->id,
        ]);
        $f->forceFill(['website_item_id' => $item->id])->save();
        Audit::record('website.review_added', "Guest feedback for {$f->reservation?->reference} shown on the website", $item, by: $by);

        return $item;
    }

    // ── Invitations ───────────────────────────────────────────────────

    /** Ask guests who checked out a few hours ago (once each). */
    public function sendInvites(?Carbon $now = null): int
    {
        $now ??= now();
        if (! $this->enabled() || ! config('hotel.feedback.invite', true)) {
            return 0;
        }
        $after = (int) config('hotel.feedback.invite_after_hours', 3);
        $sent = 0;
        Reservation::where('status', S::CheckedOut->value)
            ->whereNull('feedback_invited_at')
            ->whereNotNull('checked_out_at')
            ->where('checked_out_at', '<=', $now->copy()->subHours($after))
            ->where('checked_out_at', '>=', $now->copy()->subDays(3))   // never a survey for an old stay
            ->whereDoesntHave('feedback')
            ->with('guest')
            ->each(function (Reservation $r) use (&$sent) {
                $sent += $this->invite($r) ? 1 : 0;
            });

        return $sent;
    }

    /** Send one guest the survey link. Answers whether a text or an e-mail went out. */
    public function invite(Reservation $r): bool
    {
        $r->forceFill(['feedback_invited_at' => now()])->saveQuietly();
        $link = route('stay.feedback', app(OnlineBookingService::class)->ensureToken($r));
        $ok = false;

        try {
            $text = app(GuestMessenger::class)->aboutBooking($r, 'feedback_request', ['survey' => $link]);
            $ok = in_array($text?->status, ['sent', 'delivered', 'read'], true);
        } catch (Throwable $e) {
            Log::warning('Feedback text not sent: ' . $e->getMessage());
        }
        if ($r->guest?->email) {
            try {
                Mail::send('booking.emails.feedback', ['r' => $r, 'url' => $link],
                    fn ($m) => $m->to($r->guest->email, $r->guest->full_name)->subject('How was your stay at ' . config('hotel.name') . '?'));
                $ok = true;
            } catch (Throwable $e) {
                Log::warning('Feedback email failed: ' . $e->getMessage());
            }
        }

        return $ok;
    }

    // ── Figures ───────────────────────────────────────────────────────

    /** Scores for a period (the Guest Feedback page and the report e-mails). */
    public function summary(Carbon $from, Carbon $to): array
    {
        $rows = GuestFeedback::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $checkouts = Reservation::where('status', S::CheckedOut->value)->whereBetween('checked_out_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count();

        return [
            'count'     => $rows->count(),
            'checkouts' => $checkouts,
            'response'  => $checkouts ? (int) round(min($rows->count(), $checkouts) / $checkouts * 100) : null,
            'average'   => $rows->count() ? round($rows->avg('overall'), 1) : null,
            'nps'       => $this->nps($rows),
            'low'       => $rows->filter->isLow()->count(),
            'open'      => GuestFeedback::where('status', GuestFeedback::FOLLOW_UP)->count(),
            'stars'     => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($s) => [$s => $rows->where('overall', $s)->count()])->all(),
            'aspects'   => collect(GuestFeedback::ASPECTS)->map(function ($a, $k) use ($rows) {
                $scores = $rows->map(fn ($f) => $f->ratings[$k] ?? null)->filter();

                return ['label' => $a[0], 'icon' => $a[1], 'average' => $scores->count() ? round($scores->avg(), 1) : null, 'count' => $scores->count()];
            })->all(),
        ];
    }

    /** Net promoter score: % promoters − % detractors, or null with no answers. */
    public function nps(Collection $rows): ?int
    {
        $answered = $rows->whereNotNull('recommend');
        if ($answered->isEmpty()) {
            return null;
        }
        $pro = $answered->where('recommend', '>=', 9)->count();
        $det = $answered->where('recommend', '<=', 6)->count();

        return (int) round(($pro - $det) / $answered->count() * 100);
    }
}
