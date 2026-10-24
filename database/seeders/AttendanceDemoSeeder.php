<?php

namespace Database\Seeders;

use App\Models\Attendance\AttendancePoint;
use App\Models\Attendance\Department;
use App\Models\Attendance\Shift;
use App\Models\Attendance\StaffLeave;
use App\Models\Attendance\StaffProfile;
use App\Models\Attendance\StaffRota;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sample departments, shifts, staff and five weeks of clock-ins:
 *   php artisan db:seed --class=AttendanceDemoSeeder
 * Demo staff sign in with their email and the password Staff@123 (they are asked to change it).
 */
class AttendanceDemoSeeder extends Seeder
{
    public function run(AttendanceService $att): void
    {
        if (Shift::exists()) {
            $this->command?->warn('Shifts already exist — attendance demo not added.');
            return;
        }
        $admin = User::role('Super Admin')->first() ?? User::first();
        auth()->login($admin);
        mt_srand(2026);

        $depts = collect([
            ['Front Office', 'FO', '#7c3aed'], ['Housekeeping', 'HK', '#0d9488'], ['Food & Beverage', 'FB', '#ea580c'],
            ['Security', 'SEC', '#334155'], ['Maintenance', 'MNT', '#ca8a04'], ['Accounts & Admin', 'ACC', '#1e4a7e'],
        ])->mapWithKeys(fn ($d) => [$d[1] => Department::create(['name' => $d[0], 'code' => $d[1], 'color' => $d[2]])]);

        $shifts = collect([
            ['Morning', 'MOR', '07:00', '15:00', 0, '#0ea5e9'],
            ['Afternoon', 'AFT', '15:00', '23:00', 0, '#f59e0b'],
            ['Night', 'NGT', '23:00', '07:00', 0, '#4338ca'],
            ['Office hours', 'OFF', '08:00', '17:00', 60, '#16a34a'],
        ])->mapWithKeys(fn ($s) => [$s[1] => Shift::create(['name' => $s[0], 'code' => $s[1], 'start_time' => $s[2], 'end_time' => $s[3], 'break_minutes' => $s[4], 'color' => $s[5]])]);

        $entrance = AttendancePoint::create(['name' => 'Staff Entrance', 'code' => 'STAFF', 'location' => 'Back of house, by the time office']);
        AttendancePoint::create(['name' => 'Service Gate', 'code' => 'GATE', 'location' => 'Delivery gate', 'take_photo' => false]);

        // name, role, dept, shift, work days, title, punctuality (0 = always on time … 3 = often late), absent-ish
        $people = [
            ['Adaeze Nwankwo', 'Front Desk', 'FO', 'MOR', [1, 2, 3, 4, 5, 6], 'Receptionist', 0],
            ['Kunle Bakare', 'Front Desk', 'FO', 'AFT', [1, 2, 3, 4, 5, 7], 'Receptionist', 1],
            ['Zainab Yusuf', 'Front Desk', 'FO', 'NGT', [1, 3, 4, 5, 6, 7], 'Night auditor', 0],
            ['Blessing Okon', 'Housekeeping', 'HK', 'MOR', [1, 2, 3, 4, 5, 6], 'Room attendant', 2],
            ['Musa Garba', 'Housekeeping', 'HK', 'MOR', [2, 3, 4, 5, 6, 7], 'Room attendant', 1],
            ['Chioma Eze', 'Housekeeping', 'HK', 'MOR', [1, 2, 3, 4, 5, 6], 'Housekeeping supervisor', 0],
            ['Tobi Adebayo', 'Cashier', 'FB', 'AFT', [1, 2, 3, 4, 5, 6], 'Bartender', 3],
            ['Grace Effiong', 'Bar Manager', 'FB', 'AFT', [2, 3, 4, 5, 6, 7], 'Bar manager', 0],
            ['Samuel Danjuma', 'Cashier', 'FB', 'MOR', [1, 2, 3, 4, 5, 6], 'Waiter', 2],
            ['Ifeanyi Obi', 'Security Officer', 'SEC', 'NGT', [1, 2, 3, 4, 5, 6, 7], 'Security guard', 1],
            ['Hauwa Bello', 'Security Officer', 'SEC', 'MOR', [1, 2, 3, 4, 5, 6, 7], 'Security guard', 0],
            ['Emeka Okoro', 'Storekeeper', 'MNT', 'OFF', [1, 2, 3, 4, 5, 6], 'Storekeeper', 1],
            ['Funmi Alade', 'Accountant', 'ACC', 'OFF', [1, 2, 3, 4, 5], 'Accountant', 0],
            ['Yemi Ogunleye', 'HR Administrator', 'ACC', 'OFF', [1, 2, 3, 4, 5], 'HR officer', 0],
        ];

        $profiles = [];
        foreach ($people as $i => [$name, $role, $dept, $shift, $days, $title, $lateness]) {
            $email = Str::slug($name, '.') . '@smarthotel.test';
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name, 'username' => Str::slug($name, '.'), 'password' => Hash::make('Staff@123'), 'must_change_password' => true,
                'gender' => in_array($i, [0, 2, 3, 5, 7, 12], true) ? 'female' : 'male',
            ]);
            $user->syncRoles([$role]);
            $p = $att->ensureProfile($user);
            $p->update(['department_id' => $depts[$dept]->id, 'shift_id' => $shifts[$shift]->id, 'work_days' => $days, 'job_title' => $title,
                'employment_start' => today()->subMonths(6 + $i)->startOfMonth()]);
            $profiles[] = [$p->fresh(['shift', 'user']), $lateness];
        }

        // Leave and a rota change.
        StaffLeave::create(['user_id' => $profiles[4][0]->user_id, 'type' => 'annual', 'from_date' => today()->subDays(4), 'to_date' => today()->addDays(3), 'reason' => 'Family visit', 'created_by' => $admin->id]);
        StaffLeave::create(['user_id' => $profiles[12][0]->user_id, 'type' => 'sick', 'from_date' => today()->subDays(9), 'to_date' => today()->subDays(8), 'reason' => 'Doctor’s note received', 'created_by' => $admin->id]);
        StaffRota::create(['user_id' => $profiles[0][0]->user_id, 'work_date' => today()->subDays(2), 'shift_id' => $shifts['AFT']->id, 'note' => 'Swapped with Kunle', 'created_by' => $admin->id]);
        StaffRota::create(['user_id' => $profiles[1][0]->user_id, 'work_date' => today()->subDays(2), 'shift_id' => $shifts['MOR']->id, 'note' => 'Swapped with Adaeze', 'created_by' => $admin->id]);

        // Five weeks of scans at the staff entrance.
        $n = 0;
        for ($d = today()->subDays(34); $d->lte(today()); $d->addDay()) {
            foreach ($profiles as $k => [$p, $lateness]) {
                $sched = $att->scheduleFor($p, $d);
                if (! $sched['shift'] || $sched['leave'] || $p->employment_start->gt($d)) {
                    continue;
                }
                // Now and then someone doesn't turn up.
                if (mt_rand(1, 100) <= 2 + $lateness * 2) {
                    continue;
                }
                [$start, $end] = $sched['shift']->window($d);
                $lateBy = mt_rand(1, 10) <= $lateness * 2 ? mt_rand(12, 55) : mt_rand(-15, 6);
                $in = $start->copy()->addMinutes($lateBy)->addSeconds(mt_rand(0, 59));
                $out = $end->copy()->addMinutes(mt_rand(-5, 25))->addSeconds(mt_rand(0, 59));
                if ($in->isFuture()) {
                    continue;
                }
                $att->punch($p, ['uuid' => (string) Str::uuid(), 'punched_at' => $in, 'point_id' => $entrance->id, 'device_id' => 'DEMO'], $admin);
                $n++;
                // One person forgets to clock out once; otherwise out if the shift has ended.
                $forgot = $k === 6 && $d->isSameDay(today()->subDays(3));
                if (! $forgot && $out->isPast()) {
                    $att->punch($p, ['uuid' => (string) Str::uuid(), 'punched_at' => $out, 'point_id' => $entrance->id, 'device_id' => 'DEMO'], $admin);
                    $n++;
                }
            }
        }

        $this->command?->info(count($profiles) . ' staff in ' . $depts->count() . ' departments, ' . $n . ' demo clock-ins. Demo staff password: Staff@123');
    }
}
