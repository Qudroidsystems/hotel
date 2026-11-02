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
        'Hotel Owner'      => ['badge bg-dark',      ['Use call console', 'View all tasks', 'dashboard', 'View user', 'View role', 'View permission', 'View rooms',
                                                       'View reservations', 'View guests', 'View folios', 'View finance reports', 'View POS reports', 'View stock', 'View stock reports', 'View attendance', 'View audit log', 'View management reports', 'View housekeeping', 'View maintenance', 'View visitors', 'View incidents', 'Approve purchases', 'View IT control room', 'View expenses', 'Approve expenses', 'View company accounts', 'View night audit reports', 'View events', 'View room calendars', 'Access all hotels', 'View website enquiries', 'View network', 'View guest feedback', 'View energy saver']],
        'General Manager'  => ['badge bg-primary',   ['Use call console', 'Answer any guest call', 'Listen to guest calls', 'Manage room equipment', 'Give tasks', 'View all tasks', 'Manage tasks', 'dashboard', 'View user', 'Create user', 'Update user', 'View role', 'Add user-role', 'Update user-role',
                                                       'View rooms', 'Create rooms', 'Update rooms', 'Delete rooms', 'Change room status', 'Manage room types', 'Manage buildings',
                                                       'View reservations', 'Create reservations', 'Update reservations', 'Cancel reservations',
                                                       'Check in guests', 'Check out guests', 'Apply reservation discounts', 'View guests', 'Manage guests', 'View folios', 'Post charges', 'Void charges', 'Receive payments', 'Void payments', 'Issue refunds', 'Check out with balance', 'View finance reports', 'Review cash shifts',
                                                       'Use POS', 'Charge to room', 'Give POS discounts', 'Void POS orders', 'View POS reports', 'Manage POS menu', 'Manage hotel settings',
                                                       'View stock', 'Manage stock items', 'Receive stock', 'Transfer stock', 'Adjust stock', 'Count stock', 'Post stock counts', 'Manage suppliers', 'Manage purchase orders', 'View stock reports', 'View attendance', 'Manage attendance', 'Manage staff & shifts', 'Run attendance kiosk', 'View audit log', 'View management reports', 'View housekeeping', 'Manage housekeeping', 'View maintenance', 'Report maintenance issues', 'Manage maintenance', 'View visitors', 'Manage visitors', 'View incidents', 'Report incidents', 'Manage incidents', 'Manage lost & found', 'Request purchases', 'Approve purchases', 'Manage supplier invoices', 'Pay suppliers', 'View IT control room', 'Manage IT tickets', 'Record expenses', 'View expenses', 'Approve expenses', 'Pay expenses', 'Manage expense categories', 'Handle guest requests', 'Message guests', 'Chat with guests', 'Manage guest chat', 'Manage transport', 'Manage rates', 'View company accounts', 'Manage company accounts', 'Bill to company', 'Receive company payments', 'Run night audit', 'View night audit reports', 'Change closed days', 'View events', 'Manage events', 'Manage halls', 'Sell event tickets', 'Check event tickets', 'View room calendars', 'Manage room calendars', 'Manage website', 'View website enquiries', 'View network', 'Manage network', 'Give Wi-Fi codes', 'View guest feedback', 'Handle guest feedback', 'View energy saver', 'Manage energy saver']],
        'HR Administrator' => ['badge bg-info',      ['Give tasks', 'dashboard', 'View user', 'Create user', 'Update user', 'View role', 'Add user-role', 'Update user-role', 'Remove user-role', 'View attendance', 'Manage attendance', 'Manage staff & shifts', 'Run attendance kiosk']],
        'Front Desk'       => ['badge bg-success',   ['Give tasks', 'dashboard', 'View rooms', 'Change room status',
                                                       'View reservations', 'Create reservations', 'Update reservations', 'Cancel reservations',
                                                       'Check in guests', 'Check out guests', 'View guests', 'Manage guests', 'View folios', 'Post charges', 'Receive payments', 'View housekeeping', 'View maintenance', 'Report maintenance issues', 'View visitors', 'Manage visitors', 'Report incidents', 'Manage lost & found', 'Handle guest requests', 'Message guests', 'Chat with guests', 'Manage transport', 'View company accounts', 'Bill to company', 'Run night audit', 'View events', 'Manage events', 'Sell event tickets', 'Check event tickets', 'View room calendars', 'View website enquiries', 'Give Wi-Fi codes', 'View guest feedback', 'View energy saver']],
        'Accountant'       => ['badge bg-secondary', ['Give tasks', 'dashboard', 'View rooms', 'View reservations', 'View guests', 'View folios', 'Void charges', 'Void payments', 'Issue refunds', 'View finance reports', 'Review cash shifts',
                                                       'Void POS orders', 'View POS reports', 'Manage hotel settings',
                                                       'View stock', 'Post stock counts', 'Manage suppliers', 'View stock reports', 'View audit log', 'View management reports', 'Manage supplier invoices', 'Pay suppliers', 'Record expenses', 'View expenses', 'Approve expenses', 'Pay expenses', 'Manage expense categories', 'View company accounts', 'Manage company accounts', 'Receive company payments', 'Run night audit', 'View night audit reports', 'View events']],
        'Bar Manager'      => ['badge bg-warning',   ['Give tasks', 'dashboard', 'Chat with guests', 'Review cash shifts', 'Use POS', 'Charge to room', 'Give POS discounts', 'Void POS orders', 'View POS reports', 'Manage POS menu',
                                                       'View stock', 'Transfer stock', 'Adjust stock', 'Count stock', 'Report maintenance issues', 'Report incidents', 'Request purchases', 'Record expenses', 'View events']],
        'Cashier'          => ['badge bg-warning',   ['dashboard', 'View folios', 'Receive payments', 'Use POS', 'Charge to room', 'Chat with guests', 'Sell event tickets', 'Check event tickets']],
        'Driver'           => ['badge bg-info',      ['dashboard', 'Drive guests']],
        'Storekeeper'      => ['badge bg-secondary', ['Give tasks', 'dashboard', 'View stock', 'Manage stock items', 'Receive stock', 'Transfer stock', 'Adjust stock', 'Count stock',
                                                       'Manage suppliers', 'Manage purchase orders', 'Request purchases', 'Manage supplier invoices']],
        'Housekeeping'     => ['badge bg-info',      ['dashboard', 'Chat with guests', 'View rooms', 'Change room status', 'View reservations', 'Do housekeeping tasks', 'Report maintenance issues', 'Report incidents', 'Manage lost & found']],
        'Housekeeping Supervisor' => ['badge bg-info', ['Give tasks', 'dashboard', 'Chat with guests', 'View rooms', 'Change room status', 'View reservations', 'View housekeeping', 'Do housekeeping tasks', 'Manage housekeeping', 'View maintenance', 'Report maintenance issues', 'Report incidents', 'Manage lost & found', 'Request purchases', 'Record expenses', 'Handle guest requests', 'View events', 'View guest feedback', 'View energy saver']],
        'Maintenance Technician'  => ['badge bg-secondary', ['Use call console', 'Manage room equipment', 'dashboard', 'View rooms', 'View maintenance', 'Report maintenance issues', 'View energy saver']],
        'Security Officer' => ['badge bg-dark',      ['Use call console', 'Answer any guest call', 'Listen to guest calls', 'Give tasks', 'dashboard', 'View rooms', 'View reservations', 'View guests', 'View attendance', 'Run attendance kiosk', 'Report maintenance issues', 'View visitors', 'Manage visitors', 'View incidents', 'Report incidents', 'Manage incidents', 'Manage lost & found', 'View events', 'Check event tickets', 'View energy saver']],
        'IT Operations'    => ['badge bg-primary',   ['Use call console', 'Answer any guest call', 'Listen to guest calls', 'Manage room equipment', 'Give tasks', 'dashboard', 'View user', 'View role', 'View permission', 'Run attendance kiosk', 'View maintenance', 'Report maintenance issues', 'View IT control room', 'Manage IT monitoring', 'Manage IT tickets', 'View audit log', 'Record expenses', 'View network', 'Manage network', 'Give Wi-Fi codes', 'View energy saver', 'Manage energy saver']],
        'Qudroid Support'  => ['badge bg-light text-dark', ['dashboard', 'View user', 'View IT control room', 'Manage IT monitoring', 'Manage IT tickets', 'View network', 'Manage network']],
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
