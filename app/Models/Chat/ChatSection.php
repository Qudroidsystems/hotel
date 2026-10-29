<?php

namespace App\Models\Chat;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A part of the hotel guests can chat with or call: Reception, Restaurant, Bar, Laundry … */
class ChatSection extends Model
{
    use BelongsToHotel;

    /** The sections a hotel starts with: [key, name, icon, description, roles, opens, closes]. */
    public const DEFAULTS = [
        ['reception', 'Reception', 'ri-customer-service-2-line', 'Anything about your stay', ['Front Desk'], null, null],
        ['restaurant', 'Restaurant', 'ri-restaurant-line', 'Meals, tables and room service', ['Bar Manager', 'Cashier'], '06:30', '23:00'],
        ['bar', 'Bar', 'ri-goblet-line', 'Drinks and the lounge', ['Bar Manager', 'Cashier'], '12:00', '01:00'],
        ['laundry', 'Laundry', 'ri-t-shirt-line', 'Washing, ironing, dry cleaning', ['Housekeeping Supervisor', 'Housekeeping'], '08:00', '18:00'],
        ['housekeeping', 'Housekeeping', 'ri-brush-line', 'Cleaning, towels, extra pillows', ['Housekeeping Supervisor', 'Housekeeping'], '07:00', '22:00'],
        ['concierge', 'Concierge', 'ri-map-pin-user-line', 'Tours, tickets, directions, recommendations', ['Front Desk'], '07:00', '22:00'],
    ];

    protected $fillable = ['key', 'name', 'icon', 'description', 'phone', 'opens', 'closes', 'calls', 'roles', 'users', 'away_message', 'is_active', 'sort_order'];

    protected $casts = ['roles' => 'array', 'users' => 'array', 'calls' => 'boolean', 'is_active' => 'boolean'];

    public function conversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class, 'section_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Open now (hours may run past midnight). */
    public function isOpen(?Carbon $at = null): bool
    {
        if (! $this->opens || ! $this->closes || $this->opens === $this->closes) {
            return true;
        }
        $t = ($at ?? now())->format('H:i');

        return $this->opens < $this->closes ? ($t >= $this->opens && $t < $this->closes) : ($t >= $this->opens || $t < $this->closes);
    }

    /** "7:00 AM – 11:00 PM", or null when always open. */
    public function getHoursLabelAttribute(): ?string
    {
        if (! $this->opens || ! $this->closes || $this->opens === $this->closes) {
            return null;
        }
        $f = fn ($t) => Carbon::createFromFormat('H:i', $t)->format('g:i A');

        return $f($this->opens) . ' – ' . $f($this->closes);
    }

    /** Does this person answer this section? */
    public function staffedBy(User $user): bool
    {
        if ($user->can('Manage guest chat')) {
            return true;
        }
        if (! $user->can('Chat with guests')) {
            return false;
        }

        return in_array($user->id, (array) $this->users, true) || $user->hasAnyRole((array) $this->roles);
    }

    /** Everyone who answers it (for alerts and ringing). */
    public function staff()
    {
        $roles = array_values(array_filter((array) $this->roles));
        $ids = array_values(array_filter((array) $this->users));

        return User::where('is_disabled', false)
            ->where(function ($q) use ($roles, $ids) {
                $q->whereIn('id', $ids ?: [0]);
                if ($roles) {
                    $q->orWhereHas('roles', fn ($r) => $r->whereIn('name', $roles));
                }
            })->get()->filter(fn (User $u) => $u->can('Chat with guests'))->values();
    }
}
