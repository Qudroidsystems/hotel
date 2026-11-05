<?php

namespace App\Services\Booking;

use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Models\Booking\GuestLoginCode;
use App\Models\Booking\GuestSession;
use App\Models\Booking\GuestSocialLogin;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Messaging\GuestMessenger;
use App\Support\Audit;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Guest sign-in for the guest app. No password: the guest asks for a code,
 * it goes to the e-mail or phone they booked with, and the app keeps a session
 * for 90 days. A guest *is* that e-mail or phone: every booking made with it,
 * at every hotel of the group, shows on their account.
 */
class GuestAccountService
{
    public const CODE_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const CODES_PER_HOUR = 5;
    public const SESSION_DAYS = 90;

    /** The last code sent, kept only while e-mails go to the log on a local install (see devCodes). */
    public ?string $devCode = null;

    public function __construct(private GuestMessenger $messenger)
    {
    }

    /**
     * On a local install whose e-mail is set to "write to the log", nobody can
     * receive the code, so the sign-in screen shows it instead. Never on a live site.
     */
    public static function devCodes(): bool
    {
        return app()->environment('local') && config('app.debug') && in_array(config('mail.default'), ['log', 'array'], true);
    }

    /** ['email', 'ada@x.com'] or ['phone', '+2348031234567'], or null when it's neither. */
    public function contact(string $raw): ?array
    {
        $raw = trim($raw);
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return ['email', mb_strtolower($raw)];
        }
        $phone = $this->messenger->normalize($raw);

        return $phone && strlen(preg_replace('/\D/', '', $phone)) >= 10 ? ['phone', $phone] : null;
    }

    /** Every guest record (any hotel) with this e-mail or phone. */
    public function guests(string $contact): Collection
    {
        $q = Guest::withoutGlobalScope('hotel');
        if (str_contains($contact, '@')) {
            return $q->whereRaw('LOWER(email) = ?', [$contact])->get();
        }
        $nine = substr(preg_replace('/\D/', '', $contact), -9);

        return $q->where('phone', 'like', '%' . substr($nine, -4))->get()
            ->filter(fn (Guest $g) => substr(preg_replace('/\D/', '', (string) $g->phone), -9) === $nine)->values();
    }

    /**
     * Send a sign-in code. Says the same thing whether or not we know the
     * guest, so the form can't be used to find out who has stayed here.
     * Answers how it was sent: email, sms, or none.
     */
    public function sendCode(string $raw, ?string $ip = null): string
    {
        $this->devCode = null;
        [$type, $contact] = $this->contact($raw) ?? throw new BookingException('Enter the e-mail address or mobile number you booked with.');
        if (GuestLoginCode::where('contact', $contact)->where('created_at', '>=', now()->subHour())->count() >= self::CODES_PER_HOUR) {
            throw new BookingException('Too many codes asked for. Please wait a while and try again.');
        }
        $guests = $this->guests($contact);
        // New guests can make an account with their e-mail (to browse, book and keep their bookings in one place);
        // a phone number still has to be one the hotel knows.
        $newcomer = $guests->isEmpty();
        if ($newcomer && ($type !== 'email' || ! config('hotel.guest_app.open_sign_up', true))) {
            return 'none';
        }
        if ($type === 'phone' && ! ($this->messenger->enabled() && $this->messenger->configured())) {
            throw new BookingException('We can’t text codes at the moment. Sign in with your e-mail address instead.');
        }

        $code = (string) random_int(100000, 999999);
        GuestLoginCode::where('contact', $contact)->whereNull('used_at')->update(['used_at' => now()]);   // only the newest works
        GuestLoginCode::create(['contact' => $contact, 'code_hash' => hash('sha256', $contact . '|' . $code), 'expires_at' => now()->addMinutes(self::CODE_MINUTES), 'ip' => $ip]);
        $guest = $guests->sortByDesc('id')->first();
        $hotel = $guest ? $this->hotelName($guest) : (CurrentHotel::multi() ? (config('hotel.group_name') ?: config('hotel.name')) : config('hotel.name'));
        $text = "{$code} is your {$hotel} sign-in code. It works for " . self::CODE_MINUTES . ' minutes. If you didn’t ask for it, ignore this message.';

        if ($type === 'email') {
            try {
                Mail::raw($text, fn ($m) => $m->to($contact, $guest?->full_name)->subject("{$code} — your {$hotel} sign-in code"));
                if (self::devCodes()) {
                    $this->devCode = $code;
                }
            } catch (Throwable $e) {
                Log::warning('Guest sign-in code e-mail failed: ' . $e->getMessage());
                throw new BookingException('We couldn’t send the e-mail just now. Please try again in a minute.');
            }

            return 'email';
        }

        $sent = CurrentHotel::get()->run((int) $guest->hotel_id ?: CurrentHotel::get()->id(), fn () => $this->messenger->send($guest, null, $text, 'login_code'));
        if (! in_array($sent->status, ['sent', 'delivered', 'queued'], true)) {
            throw new BookingException('We couldn’t text the code just now. Sign in with your e-mail address instead.');
        }

        return 'sms';
    }

    /** Check a code; answers the new session's token (shown once, only its hash is kept). */
    public function verify(string $raw, string $code, ?string $device = null): array
    {
        [, $contact] = $this->contact($raw) ?? throw new BookingException('Enter the e-mail address or mobile number you booked with.');
        $row = GuestLoginCode::where('contact', $contact)->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $row || $row->attempts >= self::MAX_ATTEMPTS) {
            throw new BookingException('That code has expired. Ask for a new one.');
        }
        if (! hash_equals($row->code_hash, hash('sha256', $contact . '|' . preg_replace('/\D/', '', $code)))) {
            $row->increment('attempts');
            throw new BookingException($row->attempts >= self::MAX_ATTEMPTS ? 'Too many wrong tries. Ask for a new code.' : 'That code isn’t right. Check the latest message and try again.');
        }
        $row->forceFill(['used_at' => now()])->save();

        return $this->startSession($contact, $device, str_contains($contact, '@') ? 'e-mail' : 'phone');
    }

    /**
     * Sign in (or join) with Google or Apple. The e-mail the provider confirmed
     * is the account, so it brings the same bookings as a code to that e-mail.
     * Apple sends the name only the first time, from the app or page, not in the token.
     */
    public function socialSignIn(string $provider, string $idToken, ?string $device = null, array $name = [], ?string $nonce = null): array
    {
        $id = app(SocialSignIn::class)->verify($provider, $idToken, $nonce);
        $link = $id['sub'] !== '' ? GuestSocialLogin::where('provider', $provider)->where('subject', $id['sub'])->first() : null;
        $email = $id['email'] && $id['email_verified'] ? $id['email'] : $link?->email;
        if (! $email || $id['sub'] === '') {
            throw new BookingException('Your ' . ucfirst($provider) . ' account didn’t share a confirmed e-mail address. Sign in with your e-mail instead.');
        }
        if ($this->guests($email)->isEmpty() && ! $link && ! config('hotel.guest_app.open_sign_up', true)) {
            throw new BookingException('We couldn’t find a booking made with ' . $email . '. Use the e-mail or phone you booked with.');
        }

        $first = $name['first_name'] ?? null ?: $id['first_name'];
        $last = $name['last_name'] ?? null ?: $id['last_name'];
        $link ??= new GuestSocialLogin(['provider' => $provider, 'subject' => $id['sub']]);
        $link->fill(array_filter([
            'email' => $email, 'first_name' => $first ? mb_substr(trim($first), 0, 100) : null, 'last_name' => $last ? mb_substr(trim($last), 0, 100) : null,
        ]))->forceFill(['last_used_at' => now()])->save();

        return $this->startSession($email, $device, ucfirst($provider));
    }

    private function startSession(string $contact, ?string $device, string $via): array
    {
        $token = 'gs_' . Str::random(60);
        $session = GuestSession::create([
            'token_hash' => hash('sha256', $token), 'contact' => $contact, 'device' => $device ? mb_substr($device, 0, 120) : null,
            'last_used_at' => now(), 'expires_at' => now()->addDays(self::SESSION_DAYS),
        ]);
        Audit::record('guest.signed_in', "A guest signed in to their account ({$via})", new: ['session' => $session->id]);

        return ['token' => $token, 'session' => $session];
    }

    public function session(?string $token): ?GuestSession
    {
        if (! $token || ! str_starts_with($token, 'gs_')) {
            return null;
        }
        $s = GuestSession::where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        if ($s && (! $s->last_used_at || $s->last_used_at->lt(now()->subMinutes(10)))) {
            $s->forceFill(['last_used_at' => now()])->save();
        }

        return $s;
    }

    // ── The account ───────────────────────────────────────────────────

    /** Everything the guest app's account screen shows. */
    public function profile(GuestSession $s): array
    {
        $guests = $this->guests($s->contact);
        $latest = $guests->sortByDesc('updated_at')->first();
        $byHotel = $guests->groupBy('hotel_id');
        $stays = collect();
        $memberships = collect();
        $history = collect();
        $loyalty = app(LoyaltyService::class);

        foreach ($byHotel as $hotelId => $list) {
            CurrentHotel::get()->run((int) $hotelId ?: (int) CurrentHotel::get()->id(), function (?Hotel $hotel) use ($list, &$stays, &$memberships, &$history, $loyalty) {
                $booking = app(OnlineBookingService::class);
                $hotelName = $hotel?->name ?? config('hotel.name');
                Reservation::whereIn('guest_id', $list->modelKeys())->with('rooms.roomType:id,name')->latest('check_in_date')->limit(50)->get()
                    ->each(function (Reservation $r) use ($booking, $hotelName, $hotel, &$stays) {
                        $stays->push($this->summary($r, $booking->ensureToken($r), $hotelName, $hotel?->id));
                    });
                if ($loyalty->enabled()) {
                    foreach ($list->whereNotNull('loyalty_number')->unique('loyalty_number') as $g) {
                        $memberships->push([
                            'number' => $g->loyalty_number, 'tier' => $g->loyalty_tier, 'tier_label' => $g->tier_label, 'points' => (int) $g->loyalty_points,
                            'value_label' => config('hotel.currency_symbol') . number_format($loyalty->pointsValue((int) $g->loyalty_points), 2),
                            'hotel' => $loyalty->grouped() ? (config('hotel.group_name') ?: 'Every hotel of the group') : $hotelName,
                        ]);
                        $loyalty->history($g, 20)->each(fn ($e) => $history->push([
                            'id' => $e->id, 'date' => $e->created_at->toDateString(), 'date_label' => $e->created_at->format('j M Y'),
                            'points' => (int) $e->points, 'note' => $e->note, 'type' => $e->type, 'hotel' => $e->hotel?->name ?? $hotelName,
                        ]));
                    }
                }
            });
        }
        $stays = $stays->sortByDesc('check_in')->values();
        // A guest who joined with Google / Apple and hasn't booked yet: the name from that account.
        $social = $latest ? null : GuestSocialLogin::where('email', $s->contact)->whereNotNull('first_name')->latest('last_used_at')->first();

        return [
            'contact'     => $s->contact,
            'first_name'  => $latest?->first_name ?? $social?->first_name,
            'last_name'   => $latest?->last_name ?? $social?->last_name,
            'name'        => $latest?->full_name ?? ($social ? trim($social->first_name . ' ' . $social->last_name) : null),
            'email'       => $latest?->email ?? (str_contains($s->contact, '@') ? $s->contact : null),
            'phone'       => $latest?->phone,
            'nationality' => $latest?->nationality,
            'marketing_opt_in' => (bool) $latest?->marketing_opt_in,
            'memberships' => $memberships->unique('number')->values(),
            'points_history' => $history->unique('id')->sortByDesc('date')->take(30)->values(),
            'stays'       => $stays,
            'counts'      => [
                'stays'  => $stays->where('status', S::CheckedOut->value)->count(),
                'nights' => $stays->where('status', S::CheckedOut->value)->sum('nights'),
                'hotels' => $stays->where('status', S::CheckedOut->value)->pluck('hotel_id')->unique()->count(),
            ],
        ];
    }

    /** Change the guest's own details on every record of theirs. E-mail/phone stay: they are the sign-in. */
    public function update(GuestSession $s, array $data): void
    {
        $fields = array_intersect_key($data, array_flip(['first_name', 'last_name', 'nationality', 'marketing_opt_in']));
        foreach ($this->guests($s->contact) as $g) {
            $g->forceFill($fields)->saveQuietly();
        }
        $names = array_filter(array_intersect_key($fields, array_flip(['first_name', 'last_name'])));
        if ($names) {
            GuestSocialLogin::where('email', $s->contact)->update($names);
        }
    }

    public function signOut(GuestSession $s): void
    {
        $s->delete();
    }

    /** The same keys the app keeps for a booking on the phone, plus its hotel. */
    private function summary(Reservation $r, string $token, string $hotelName, ?int $hotelId): array
    {
        [$state, $label] = match ($r->status) {
            S::Pending    => ['warn', 'Waiting for your deposit'],
            S::Confirmed  => ['ok', 'Confirmed'],
            S::CheckedIn  => ['ok', 'You’re staying with us'],
            S::CheckedOut => ['done', 'Checked out'],
            S::Cancelled  => ['bad', 'Cancelled'],
            S::NoShow     => ['bad', 'No-show'],
        };

        return [
            'token' => $token, 'reference' => $r->reference, 'status' => $r->status->value, 'status_label' => $label, 'state' => $state,
            'check_in' => $r->check_in_date->toDateString(), 'check_out' => $r->check_out_date->toDateString(),
            'check_in_label' => $r->check_in_date->format('D j M Y'), 'nights' => $r->nights,
            'room_types' => $r->rooms->groupBy('room_type_id')->map(fn ($l) => ($l->count() > 1 ? $l->count() . ' × ' : '') . $l->first()->roomType?->name)->implode(', '),
            'hotel' => ['name' => $hotelName], 'hotel_id' => $hotelId,
        ];
    }

    private function hotelName(Guest $g): string
    {
        return CurrentHotel::multi() ? (config('hotel.group_name') ?: (Hotel::find($g->hotel_id)?->name ?? config('hotel.name'))) : config('hotel.name');
    }
}
