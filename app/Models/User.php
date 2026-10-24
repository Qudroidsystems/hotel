<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone_number',
        'gender',
        'date_of_birth',
        'nationality',
        'address',
        'avatar',
        'is_disabled',
        'must_change_password',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'    => 'datetime',
        'date_of_birth'        => 'date',
        'must_change_password' => 'boolean',
        'is_disabled'          => 'boolean',
        'last_login_at'        => 'datetime',
    ];

    /** Folder (on the public disk) that profile pictures are stored in. */
    public const AVATAR_DIR = 'avatars';

    public function getFirstNameAttribute(): string
    {
        return explode(' ', trim((string) $this->name))[0] ?? '';
    }

    public function getLastNameAttribute(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name));
        return count($parts) > 1 ? end($parts) : '';
    }

    /** Two-letter initials for avatar placeholders, e.g. "John Adeyemi" → "JA". */
    public function getInitialsAttribute(): string
    {
        $initials = collect(preg_split('/\s+/', trim((string) $this->name)))
            ->filter()
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
            ->take(2)
            ->implode('');

        return $initials !== '' ? $initials : 'U';
    }

    /** Generated letter avatar used when no picture has been uploaded. */
    /** Initials on a coloured circle, drawn locally (no outside service, works without internet). */
    public function getFallbackAvatarUrlAttribute(): string
    {
        $palette = ['#1e4a7e', '#0d9488', '#7c3aed', '#ea580c', '#0284c7', '#16a34a', '#db2777', '#475569'];
        $bg = $palette[crc32((string) ($this->name ?: 'User')) % count($palette)];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
            . '<rect width="128" height="128" fill="' . $bg . '"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="52" font-weight="600" fill="#fff">'
            . htmlspecialchars($this->initials, ENT_QUOTES) . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return asset('storage/' . self::AVATAR_DIR . '/' . basename($this->avatar));
        }

        return $this->fallback_avatar_url;
    }

    public function getFormattedDobAttribute(): ?string
    {
        return $this->date_of_birth?->format('d M Y');
    }

    public function isActive(): bool
    {
        return ! $this->is_disabled;
    }

    public function scopeActive($query)
    {
        return $query->where('is_disabled', false);
    }

    /**
     * Colour class for a role pill on the user screens. Administrative roles
     * are pink; every other role gets a stable colour from its name.
     */
    public static function rolePillClass(string $role): string
    {
        $key = strtolower($role);

        if (in_array($key, ['super admin', 'admin', 'hotel owner'], true)) {
            return 'pink';
        }

        $palette = ['blue', 'green', 'amber'];

        return $palette[crc32($key) % count($palette)];
    }

    public function staffProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\Attendance\StaffProfile::class);
    }
}
