<?php

namespace App\Services\Mobile;

use App\Models\User;

/** The signed-in person as the staff mobile app reads them (login and GET /auth/me). */
class MobileUser
{
    /** App features and the permissions that open them. */
    public const FEATURES = [
        'work'       => ['Do housekeeping tasks', 'Manage housekeeping', 'View maintenance', 'Manage maintenance', 'Handle guest requests', 'Manage IT tickets', 'Charge to room', 'Drive guests', 'Manage transport'],
        'approvals'  => ['Approve expenses', 'Approve purchases', 'Review cash shifts', 'Post stock counts'],
        'front_desk' => ['View reservations', 'Check in guests'],
        'rooms'      => ['View rooms', 'Manage housekeeping', 'Check in guests'],
        'report'     => ['Report maintenance issues', 'Manage maintenance'],
        'today'      => ['View management reports', 'View finance reports', 'Manage hotel settings', 'Check in guests', 'Manage housekeeping'],
        'night_audit' => ['Run night audit', 'View night audit reports'],
        'events'     => ['View events', 'Manage events'],
        'bill_company' => ['Bill to company'],
        'group'      => ['Access all hotels'],   // several hotels only
        'owner'      => ['View management reports', 'View finance reports'],
        'driver'     => ['Drive guests'],
        'transport'  => ['Manage transport'],
    ];

    public static function toArray(User $user): array
    {
        $super = $user->hasRole('Super Admin');
        $roles = $user->getRoleNames()->values();

        return [
            'id'                   => $user->id,
            'name'                 => $user->name,
            'first_name'           => $user->first_name,
            'email'                => $user->email,
            'username'             => $user->username,
            'phone_number'         => $user->phone_number,
            'user_type'            => 'staff',
            'user_type_label'      => $roles->first() ?? 'Staff',
            'job_title'            => $roles->first(),
            'avatar_url'           => $user->avatar ? $user->avatar_url : null,
            'initials'             => $user->initials,
            'roles'                => $roles,
            'permissions'          => $super ? ['*'] : $user->getAllPermissions()->pluck('name')->values(),
            'modules'              => self::features($user),
            'must_change_password' => (bool) $user->must_change_password,
            'business_unit'        => ['name' => config('hotel.name')],
            // Multi-hotel: the hotels this person works at; the app sends the chosen one as the X-Hotel header.
            'multi_hotel'          => \App\Support\Tenancy\CurrentHotel::multi(),
            'hotel'                => ($h = \App\Support\Tenancy\CurrentHotel::get()->hotel()) ? ['id' => $h->id, 'name' => $h->name, 'city' => $h->city] : null,
            'hotels'               => \App\Support\Tenancy\CurrentHotel::multi()
                ? $user->accessibleHotels()->map(fn ($h) => ['id' => $h->id, 'name' => $h->name, 'city' => $h->city])->values()
                : [],
        ];
    }

    public static function features(User $user): array
    {
        return collect(self::FEATURES)->filter(fn ($perms) => $user->canAny($perms))->keys()
            ->reject(fn ($k) => $k === 'group' && ! \App\Support\Tenancy\CurrentHotel::multi())->values()->all();
    }
}
