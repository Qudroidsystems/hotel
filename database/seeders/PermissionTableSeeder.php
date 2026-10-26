<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Core permissions. The "title" groups them in the role editor.
 * Each new hotel module adds its own group here (or in its own seeder).
 * Safe to run repeatedly.
 */
class PermissionTableSeeder extends Seeder
{
    public const PERMISSIONS = [
        'Dashboard' => [
            'dashboard',
        ],
        'User Management' => [
            'View user',
            'Create user',
            'Update user',
            'Delete user',
        ],
        'Role Management' => [
            'View role',
            'Create role',
            'Update role',
            'Delete role',
            'Add user-role',
            'Update user-role',
            'Remove user-role',
        ],
        'Room Management' => [
            'View rooms',
            'Create rooms',
            'Update rooms',
            'Delete rooms',
            'Change room status',
            'Manage room types',
            'Manage buildings',
        ],
        'Housekeeping' => [
            'View housekeeping',
            'Do housekeeping tasks',
            'Manage housekeeping',
        ],
        'Maintenance' => [
            'View maintenance',
            'Report maintenance issues',
            'Manage maintenance',
        ],
        'Security' => [
            'View visitors',
            'Manage visitors',
            'View incidents',
            'Report incidents',
            'Manage incidents',
            'Manage lost & found',
        ],
        'Reservations' => [
            'View reservations',
            'Create reservations',
            'Update reservations',
            'Cancel reservations',
            'Check in guests',
            'Check out guests',
            'Apply reservation discounts',
        ],
        'Billing & Payments' => [
            'View folios',
            'Post charges',
            'Void charges',
            'Receive payments',
            'Void payments',
            'Issue refunds',
            'Check out with balance',
            'View finance reports',
            'Review cash shifts',
        ],
        'Bar & Restaurant (POS)' => [
            'Use POS',
            'Charge to room',
            'Give POS discounts',
            'Void POS orders',
            'View POS reports',
            'Manage POS menu',
        ],
        'Inventory' => [
            'View stock',
            'Manage stock items',
            'Receive stock',
            'Transfer stock',
            'Adjust stock',
            'Count stock',
            'Post stock counts',
            'Manage suppliers',
            'Manage purchase orders',
            'View stock reports',
        ],
        'Procurement' => [
            'Request purchases',
            'Approve purchases',
            'Manage supplier invoices',
            'Pay suppliers',
        ],
        'Staff Attendance' => [
            'View attendance',
            'Manage attendance',
            'Manage staff & shifts',
            'Run attendance kiosk',
        ],
        'Reports' => [
            'View management reports',
        ],
        'Audit & Security' => [
            'View audit log',
        ],
        'Guest Services' => [
            'Handle guest requests',
            'Message guests',
        ],
        'Expenses' => [
            'Record expenses',
            'View expenses',
            'Approve expenses',
            'Pay expenses',
            'Manage expense categories',
        ],
        'IT Control Room' => [
            'View IT control room',
            'Manage IT monitoring',
            'Manage IT tickets',
        ],
        'Hotel Settings' => [
            'Manage hotel settings',
        ],
        'Rates & Seasons' => [
            'Manage rates',
        ],
        'Company Accounts' => [
            'View company accounts',
            'Manage company accounts',
            'Bill to company',
            'Receive company payments',
        ],
        'Night Audit' => [
            'Run night audit',
            'View night audit reports',
            'Change closed days',
        ],
        'Events & Halls' => [
            'View events',
            'Manage events',
            'Manage halls',
        ],
        'Hotels' => [
            'Manage hotels',
            'Access all hotels',
        ],
        'Room Calendars' => [
            'View room calendars',
            'Manage room calendars',
        ],
        'Guest Management' => [
            'View guests',
            'Manage guests',
        ],
        'Permission Management' => [
            'View permission',
            'Create permission',
            'Update permission',
            'Delete permission',
        ],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $title => $names) {
            foreach ($names as $name) {
                Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['title' => $title]
                );
            }
        }
    }
}
