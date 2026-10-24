<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The hotel's standard roles (from the Smart Hotel specification) and the
 * core permissions each one starts with. Module permissions are added to
 * these roles as each module is built. Existing roles are never stripped of
 * permissions an administrator has granted since — this only adds.
 */
class RoleTableSeeder extends Seeder
{
    /** role => [badge class, permissions] ('*' = every permission) */
    public const ROLES = [
        'Super Admin'      => ['badge bg-danger',    ['*']],
        'Hotel Owner'      => ['badge bg-dark',      ['dashboard', 'View user', 'View role', 'View permission', 'View rooms',
                                                       'View reservations', 'View guests', 'View folios', 'View finance reports', 'View POS reports', 'View stock', 'View stock reports', 'View attendance', 'View audit log', 'View management reports', 'View housekeeping', 'View maintenance', 'View visitors', 'View incidents', 'Approve purchases', 'View IT control room', 'View expenses', 'Approve expenses']],
        'General Manager'  => ['badge bg-primary',   ['dashboard', 'View user', 'Create user', 'Update user', 'View role', 'Add user-role', 'Update user-role',
                                                       'View rooms', 'Create rooms', 'Update rooms', 'Delete rooms', 'Change room status', 'Manage room types', 'Manage buildings',
                                                       'View reservations', 'Create reservations', 'Update reservations', 'Cancel reservations',
                                                       'Check in guests', 'Check out guests', 'Apply reservation discounts', 'View guests', 'Manage guests', 'View folios', 'Post charges', 'Void charges', 'Receive payments', 'Void payments', 'Issue refunds', 'Check out with balance', 'View finance reports', 'Review cash shifts',
                                                       'Use POS', 'Charge to room', 'Give POS discounts', 'Void POS orders', 'View POS reports', 'Manage POS menu', 'Manage hotel settings',
                                                       'View stock', 'Manage stock items', 'Receive stock', 'Transfer stock', 'Adjust stock', 'Count stock', 'Post stock counts', 'Manage suppliers', 'Manage purchase orders', 'View stock reports', 'View attendance', 'Manage attendance', 'Manage staff & shifts', 'Run attendance kiosk', 'View audit log', 'View management reports', 'View housekeeping', 'Manage housekeeping', 'View maintenance', 'Report maintenance issues', 'Manage maintenance', 'View visitors', 'Manage visitors', 'View incidents', 'Report incidents', 'Manage incidents', 'Manage lost & found', 'Request purchases', 'Approve purchases', 'Manage supplier invoices', 'Pay suppliers', 'View IT control room', 'Manage IT tickets', 'Record expenses', 'View expenses', 'Approve expenses', 'Pay expenses', 'Manage expense categories']],
        'HR Administrator' => ['badge bg-info',      ['dashboard', 'View user', 'Create user', 'Update user', 'View role', 'Add user-role', 'Update user-role', 'Remove user-role', 'View attendance', 'Manage attendance', 'Manage staff & shifts', 'Run attendance kiosk']],
        'Front Desk'       => ['badge bg-success',   ['dashboard', 'View rooms', 'Change room status',
                                                       'View reservations', 'Create reservations', 'Update reservations', 'Cancel reservations',
                                                       'Check in guests', 'Check out guests', 'View guests', 'Manage guests', 'View folios', 'Post charges', 'Receive payments', 'View housekeeping', 'View maintenance', 'Report maintenance issues', 'View visitors', 'Manage visitors', 'Report incidents', 'Manage lost & found']],
        'Accountant'       => ['badge bg-secondary', ['dashboard', 'View rooms', 'View reservations', 'View guests', 'View folios', 'Void charges', 'Void payments', 'Issue refunds', 'View finance reports', 'Review cash shifts',
                                                       'Void POS orders', 'View POS reports', 'Manage hotel settings',
                                                       'View stock', 'Post stock counts', 'Manage suppliers', 'View stock reports', 'View audit log', 'View management reports', 'Manage supplier invoices', 'Pay suppliers', 'Record expenses', 'View expenses', 'Approve expenses', 'Pay expenses', 'Manage expense categories']],
        'Bar Manager'      => ['badge bg-warning',   ['dashboard', 'Review cash shifts', 'Use POS', 'Charge to room', 'Give POS discounts', 'Void POS orders', 'View POS reports', 'Manage POS menu',
                                                       'View stock', 'Transfer stock', 'Adjust stock', 'Count stock', 'Report maintenance issues', 'Report incidents', 'Request purchases', 'Record expenses']],
        'Cashier'          => ['badge bg-warning',   ['dashboard', 'View folios', 'Receive payments', 'Use POS', 'Charge to room']],
        'Storekeeper'      => ['badge bg-secondary', ['dashboard', 'View stock', 'Manage stock items', 'Receive stock', 'Transfer stock', 'Adjust stock', 'Count stock',
                                                       'Manage suppliers', 'Manage purchase orders', 'Request purchases', 'Manage supplier invoices']],
        'Housekeeping'     => ['badge bg-info',      ['dashboard', 'View rooms', 'Change room status', 'View reservations', 'Do housekeeping tasks', 'Report maintenance issues', 'Report incidents', 'Manage lost & found']],
        'Housekeeping Supervisor' => ['badge bg-info', ['dashboard', 'View rooms', 'Change room status', 'View reservations', 'View housekeeping', 'Do housekeeping tasks', 'Manage housekeeping', 'View maintenance', 'Report maintenance issues', 'Report incidents', 'Manage lost & found', 'Request purchases', 'Record expenses']],
        'Maintenance Technician'  => ['badge bg-secondary', ['dashboard', 'View rooms', 'View maintenance', 'Report maintenance issues']],
        'Security Officer' => ['badge bg-dark',      ['dashboard', 'View rooms', 'View reservations', 'View guests', 'View attendance', 'Run attendance kiosk', 'Report maintenance issues', 'View visitors', 'Manage visitors', 'View incidents', 'Report incidents', 'Manage incidents', 'Manage lost & found']],
        'IT Operations'    => ['badge bg-primary',   ['dashboard', 'View user', 'View role', 'View permission', 'Run attendance kiosk', 'View maintenance', 'Report maintenance issues', 'View IT control room', 'Manage IT monitoring', 'Manage IT tickets', 'View audit log', 'Record expenses']],
        'Qudroid Support'  => ['badge bg-light text-dark', ['dashboard', 'View user', 'View IT control room', 'Manage IT monitoring', 'Manage IT tickets']],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::ROLES as $name => [$badge, $permissions]) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['badge' => $badge]);

            $grant = $permissions === ['*']
                ? Permission::where('guard_name', 'web')->pluck('name')->all()
                : $permissions;

            $role->givePermissionTo($grant);
        }
    }
}
