<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Room;
use App\Services\Mobile\AppPreferences;
use App\Services\Mobile\MobileUser;
use App\Services\Mobile\StaffActions;
use App\Services\Staff\StaffAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** The app's branding, the home screen, front desk, rooms and the person's own profile. */
class AppController extends ApiController
{
    public function __construct(private StaffAppService $app, private StaffActions $actions)
    {
    }

    /** GET /app/config — before anyone signs in: the hotel's name, logo and colour. */
    public function config()
    {
        $logo = config('hotel.has_logo') ? config('hotel.logo') : null;

        return $this->ok([
            'api_version'   => 1,
            'kind'          => 'smart-hotel',
            'staff_api'     => class_exists(\Laravel\Sanctum\Sanctum::class),
            'name'          => config('hotel.name'),
            'company'       => config('hotel.name'),
            'tagline'       => config('hotel.tagline'),
            'logo_url'      => $logo ? asset($logo) : null,
            'primary_color' => config('hotel.app_color', '#0f766e'),
            'website'       => url('/book'),
            'support_email' => config('hotel.booking.contact_email'),
            'support_phone' => config('hotel.booking.contact_phone'),
            'currency'      => config('hotel.currency'),
            'currency_symbol' => config('hotel.currency_symbol'),
            'timezone'      => config('app.timezone'),
            'modules'       => [],
            // Several hotels: the guest app lets guests choose one (GET /guest/hotels).
            'multi_hotel'   => \App\Support\Tenancy\CurrentHotel::multi(),
            'group_name'    => \App\Support\Tenancy\CurrentHotel::multi() ? (config('hotel.group_name') ?: config('app.name')) : null,
            // How both apps draw maps (OpenStreetMap, or Google with the hotel's map key) and whether address search works.
            'maps'          => app(\App\Services\Maps\MapsService::class)->tiles() + ['search' => app(\App\Services\Maps\MapsService::class)->google()],
        ]);
    }

    /** GET /home — everything the Today screen shows, in one call. */
    public function home(Request $request)
    {
        $user = $request->user();
        $features = MobileUser::features($user);
        $work = $this->actions->work($user);
        $approvals = $this->actions->canApprove($user) ? $this->actions->approvals($user) : [];
        $att = $this->app->attendance($user);

        return $this->ok([
            'greeting'      => match (true) { now()->hour < 12 => 'Good morning', now()->hour < 17 => 'Good afternoon', default => 'Good evening' },
            'date'          => now()->format('l j F'),
            'hotel'         => config('hotel.name'),
            'features'      => $features,
            'counts'        => ['work' => count($work), 'approvals' => count($approvals), 'urgent' => collect($work)->where('urgent', true)->count(),
                'unread_notifications' => $user->unreadNotifications()->count()],
            'work'          => array_slice($work, 0, 4),
            'approvals'     => array_slice($approvals, 0, 3),
            'today'         => in_array('today', $features, true) ? $this->today() : null,
            'attendance'    => $att ? [
                'is_in'    => (bool) $att->is_in,
                'first_in' => $att->first_in?->format('g:i A'),
                'last_out' => $att->last_out?->format('g:i A'),
                'line'     => $att->is_in ? 'Clocked in at ' . $att->first_in?->format('g:i A') : 'Clocked out at ' . $att->last_out?->format('g:i A'),
            ] : null,
        ]);
    }

    /** GET /front-desk — today's arrivals and departures. */
    public function frontDesk(Request $request)
    {
        abort_unless($request->user()->canAny(['View reservations', 'Check in guests']), 403);
        $desk = $this->app->frontDesk();
        foreach ($desk as $list) {
            $list->loadMissing(['folio', 'company']);
        }
        $row = fn ($r) => [
            'id'        => $r->id,
            'reference' => $r->reference,
            'guest'     => $r->guest?->full_name ?? '—',
            'phone'     => $r->guest?->phone,
            'vip'       => (bool) $r->guest?->is_vip,
            'rooms'     => $r->rooms->map(fn ($rr) => $rr->room?->number)->filter()->values(),
            'nights'    => $r->nights ?? null,
            'adults'    => $r->adults ?? null,
            'status'    => $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status,
            'status_label' => $r->status instanceof \BackedEnum && method_exists($r->status, 'label') ? $r->status->label() : ucfirst(str_replace('_', ' ', (string) ($r->status instanceof \BackedEnum ? $r->status->value : $r->status))),
            'arrival_time' => $r->expected_arrival_time,
            // The guest is sharing their way here from the guest app: "On the way · 12 min" / "At the hotel".
            'on_the_way' => app(\App\Services\Booking\ArrivalService::class)->forStaff($r),
            'url'       => route('reservations.show', $r),
            // For billing at departure: what is owed and whose account it may go to.
            'folio_id'  => $r->folio?->id,
            'balance'   => $r->folio ? round($r->folio->balance(), 2) : 0,
            'company'   => $r->company ? ['id' => $r->company->id, 'name' => $r->company->name] : null,
        ];

        return $this->ok([
            'today'      => $this->today(),
            'arrivals'   => $desk['arrivals']->map($row)->values(),
            'departures' => $desk['departures']->map($row)->values(),
        ]);
    }

    /** GET /rooms — every room and its status (read only). */
    public function rooms(Request $request)
    {
        abort_unless($request->user()->canAny(MobileUser::FEATURES['rooms']), 403);
        $rooms = Room::active()->with(['roomType:id,name', 'floor:id,name'])->orderBy('number')->get();

        return $this->ok($rooms->map(function ($r) {
            $status = $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status;

            return [
                'id'     => $r->id,
                'number' => $r->number,
                'type'   => $r->roomType?->name,
                'floor'  => $r->floor?->name,
                'status' => $status,
                'status_label' => $r->status instanceof \BackedEnum && method_exists($r->status, 'label') ? $r->status->label() : ucfirst(str_replace('_', ' ', $status)),
            ];
        })->values(), meta: ['summary' => $rooms->countBy(fn ($r) => $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status)]);
    }

    // ── The person's own profile and settings ─────────────────────────

    public function profile(Request $request)
    {
        $u = $request->user();

        return $this->ok([
            'name' => $u->name, 'email' => $u->email, 'username' => $u->username, 'phone_number' => $u->phone_number,
            'gender' => $u->gender, 'date_of_birth' => $u->date_of_birth?->toDateString(), 'address' => $u->address,
            'avatar_url' => $u->avatar ? $u->avatar_url : null, 'roles' => $u->getRoleNames(),
        ]);
    }

    public function saveProfile(Request $request)
    {
        $data = $request->validate([
            'name'          => 'sometimes|required|string|max:120',
            'phone_number'  => 'sometimes|nullable|string|max:20',
            'gender'        => 'sometimes|nullable|in:male,female,other',
            'date_of_birth' => 'sometimes|nullable|date|before:today',
            'address'       => 'sometimes|nullable|string|max:500',
        ]);
        $request->user()->update($data);

        return $this->profile($request)->setStatusCode(200);
    }

    public function preferences(Request $request)
    {
        return $this->ok(AppPreferences::for($request->user())->preferences());
    }

    public function savePreferences(Request $request)
    {
        $data = $request->validate(['push_enabled' => 'sometimes|boolean']);
        $prefs = AppPreferences::for($request->user());
        if (array_key_exists('push_enabled', $data)) {
            $prefs->update(['push' => $data['push_enabled']]);
        }

        return $this->ok($prefs->preferences(), 'Saved.');
    }

    private function today(): array
    {
        $t = $this->app->today();
        $sym = config('hotel.currency_symbol');

        return [
            'occupancy'  => $t['occupancy'],
            'occupied'   => $t['occupied'],
            'rooms'      => $t['rooms'],
            'in_house'   => $t['in_house'],
            'arrivals'   => $t['arrivals'],
            'arrived'    => $t['arrived'],
            'departures' => $t['departures'],
            'dirty'      => $t['dirty'],
            'maintenance' => $t['maintenance'],
            'it_down'    => $t['it_down'],
            'collected'  => $t['collected'],
            'collected_label' => $sym . number_format($t['collected'], 0),
            'room_status' => $t['room_status'],
        ];
    }
}
