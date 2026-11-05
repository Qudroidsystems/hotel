<?php

namespace App\Services\Mobile\Modules;

use App\Models\User;
use Illuminate\Support\Collection;

/** Every area of the hotel system the staff app shows on its "More" page. */
class ModuleRegistry
{
    public const MODULES = [
        TasksModule::class,
        StaysModule::class,
        CardPaymentsModule::class,
        RoomKeysModule::class,
        DispatchModule::class,
        FleetModule::class,
        VisitorsModule::class,
        IncidentsModule::class,
        LostFoundModule::class,
        FeedbackModule::class,
        ExpensesModule::class,
    ];

    /** @return Collection<int, StaffModule> */
    public function all(): Collection
    {
        return collect(self::MODULES)->map(fn ($c) => app($c));
    }

    /** @return Collection<int, StaffModule> */
    public function for(User $user): Collection
    {
        return $this->all()->filter(fn (StaffModule $m) => $m->allowed($user))->values();
    }

    public function get(string $key): ?StaffModule
    {
        return $this->all()->first(fn (StaffModule $m) => $m->key() === $key);
    }
}
