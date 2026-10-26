<?php

namespace App\Support\Tenancy;

use App\Models\Hotel;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Which hotel the current request (or command) is working in. Every hotel record is read and written for
 * this hotel only. In a single-hotel system it is always hotel #1. One instance per request (scoped).
 */
class CurrentHotel
{
    private ?int $id = null;
    private ?Hotel $hotel = null;
    private bool $all = false;
    private ?int $default = null;

    public static function get(): self
    {
        return app(self::class);
    }

    /** The hotel's id, or null while working across all hotels (group reports) or before hotels exist. */
    public function id(): ?int
    {
        if ($this->all) {
            return null;
        }

        return $this->id ??= $this->defaultId();
    }

    public function hotel(): ?Hotel
    {
        $id = $this->id();
        if (! $id) {
            return null;
        }
        if ($this->hotel?->id !== $id) {
            $this->hotel = Hotel::find($id);
        }

        return $this->hotel;
    }

    public function set(Hotel|int $hotel): void
    {
        $this->id = $hotel instanceof Hotel ? $hotel->id : $hotel;
        $this->hotel = $hotel instanceof Hotel ? $hotel : null;
        $this->all = false;
    }

    /** Run something for one hotel, then go back to the hotel before. */
    public function run(Hotel|int $hotel, callable $fn): mixed
    {
        [$id, $model, $all] = [$this->id, $this->hotel, $this->all];
        $before = $this->id();
        $this->set($hotel);
        // Another hotel: its own name, taxes, accounts … (one hotel: settings are already in place).
        $switch = self::multi() && $before !== $this->id;
        if ($switch) {
            \App\Support\HotelSettings::applyFor($this->id);
        }
        try {
            return $fn($this->hotel());
        } finally {
            [$this->id, $this->hotel, $this->all] = [$id, $model, $all];
            if ($switch) {
                \App\Support\HotelSettings::applyFor($this->id());
            }
        }
    }

    /** Run something across every hotel (group figures). */
    public function across(callable $fn): mixed
    {
        $all = $this->all;
        $this->all = true;
        try {
            return $fn();
        } finally {
            $this->all = $all;
        }
    }

    /** Run something once for each active hotel (scheduled jobs). */
    public function each(callable $fn): void
    {
        foreach ($this->hotels() as $hotel) {
            $this->run($hotel, $fn);
        }
    }

    /**
     * Public links (a guest's booking page, a payment coming back) find their record in any hotel, then
     * carry on in that record's hotel.
     */
    public function adopt(?\Illuminate\Database\Eloquent\Model $model): void
    {
        $id = $model?->getAttribute('hotel_id');
        if ($id && $id !== $this->id()) {
            $this->set((int) $id);
            if (self::multi()) {
                \App\Support\HotelSettings::applyFor((int) $id);
            }
        }
    }

    public function isAcross(): bool
    {
        return $this->all;
    }

    /** Active hotels, in order. */
    public function hotels()
    {
        try {
            return Hotel::active()->ordered()->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /** True when the system is set up for several hotels. */
    public static function multi(): bool
    {
        return (bool) config('hotel.multi_hotel', false);
    }

    private function defaultId(): ?int
    {
        // Not remembered until hotels exist (the first request after installing runs before the tables do).
        if (! $this->default) {
            try {
                $this->default = Schema::hasTable('hotels') ? (int) (Hotel::orderBy('id')->value('id') ?? 0) : 0;
            } catch (Throwable) {
                $this->default = 0;
            }
        }

        return $this->default ?: null;
    }
}
