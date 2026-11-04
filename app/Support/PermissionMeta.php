<?php

namespace App\Support;

/**
 * Turns a raw permission name (e.g. "View student", "Manage payroll settings")
 * into a friendly action chip + a plain-English description, so the role editor
 * can explain what each permission actually allows. Works for every permission
 * with no database changes; specific names can be given an exact description in
 * the OVERRIDES map below.
 */
class PermissionMeta
{
    /** Verb => [chip label, sentence template]. {x} = the rest of the permission name. */
    protected const VERBS = [
        'View'     => ['View',    'See {x} — read-only, no changes.'],
        'Show'     => ['View',    'See {x}.'],
        'Preview'  => ['Preview', 'Preview {x} before it is finalised.'],
        'Create'   => ['Create',  'Add new {x}.'],
        'Add'      => ['Add',     'Add {x}.'],
        'Update'   => ['Edit',    'Edit existing {x}.'],
        'Edit'     => ['Edit',    'Edit existing {x}.'],
        'Manage'   => ['Manage',  'Full control of {x} — add, edit and remove.'],
        'Delete'   => ['Delete',  'Permanently delete {x}. This cannot be undone.'],
        'Remove'   => ['Remove',  'Remove {x}.'],
        'Approve'  => ['Approve', 'Approve {x}.'],
        'Submit'   => ['Submit',  'Submit {x} for approval.'],
        'Release'  => ['Release', 'Release {x} (money leaves the account).'],
        'Pay'      => ['Pay',     'Pay {x}.'],
        'Post'     => ['Post',    'Post {x} to the records / ledger.'],
        'Close'    => ['Close',   'Close {x}.'],
        'Download' => ['Download','Download {x}.'],
        'Print'    => ['Print',   'Print {x}.'],
        'Generate' => ['Generate','Generate {x}.'],
        'Export'   => ['Export',  'Export {x}.'],
        'Import'   => ['Import',  'Import {x}.'],
        'Send'     => ['Send',    'Send {x}.'],
        'Assign'   => ['Assign',  'Assign {x}.'],
    ];

    /** Exact-name overrides for permissions the generic rule can't phrase well. */
    protected const OVERRIDES = [
        'dashboard'                 => ['View',   'Open the main administration dashboard.'],
        'finance dashboard'         => ['View',   'See the finance analytics dashboard.'],
        'academics dashboard'       => ['View',   'See the academics analytics dashboard.'],
        'View management dashboard' => ['View',   'See the management overview dashboard.'],
        'Add user-role'             => ['Assign', 'Add users to this role.'],
        'Update user-role'          => ['Edit',   'Change the users assigned to this role.'],
        'Remove user-role'          => ['Remove', 'Remove users from this role.'],
        'Manage maintenance mode'   => ['Manage', 'Turn the portal\'s maintenance mode on or off.'],
        'Manage feature flags'      => ['Manage', 'Switch portal modules on or off (module access).'],
        'Release salary payments'   => ['Release','Release staff salary payments — the money is sent.'],
        'Manage payment gateways'   => ['Manage', 'Configure the payment gateways (Paystack, OPay).'],
        'Close accounting period'   => ['Close',  'Lock the accounting books up to a date.'],
        'Post journal entries'      => ['Post',   'Post entries directly into the general ledger.'],
        'Manage website'            => ['Manage', 'Change the public website: its pictures, texts, dining, offers, gallery, journal and settings.'],
        'View website enquiries'    => ['View',   'Read and answer messages, event enquiries and table requests sent from the website, and see newsletter sign-ups.'],
        'Use POS'                   => ['Use',    'Sell at the bar / restaurant till, including when the network is down.'],
        'Charge to room'            => ['Charge', 'Put a bar or restaurant sale on an in-house guest\'s bill.'],
        'Give POS discounts'        => ['Discount','Take money off a bar or restaurant sale.'],
        'Void POS orders'           => ['Void',   'Cancel a bar or restaurant sale (and its room charge) with a reason.'],
        'Check out with balance'    => ['Approve','Let a guest check out while they still owe money (a reason is required).'],
        'Manage rates'              => ['Manage', 'Set rate plans, seasons, promo codes and the nightly rate calendar.'],
        'Bill to company'           => ['Charge', 'Move a guest\'s bill to their company\'s account at check-out (within its credit limit).'],
        'Receive company payments'  => ['Receive','Record payments from companies against their accounts.'],
        'Run night audit'           => ['Run',    'Close the business day: post room charges, mark no-shows and print the day\'s report.'],
        'View night audit reports'  => ['View',   'See the daily reports saved by each night audit.'],
        'Change closed days'        => ['Approve','Void charges or payments dated on a day the night audit has closed.'],
        'Manage halls'              => ['Manage', 'Set up halls, their rates and the event packages.'],
        'Sell event tickets'        => ['Create', 'Sell tickets for events at the desk (paid or charged to a room), reprint them and cancel orders.'],
        'Check event tickets'       => ['Use',    'Scan tickets at the door and let holders in.'],
        'Receive stock'             => ['Receive','Book goods delivered by suppliers into a store (goods received note).'],
        'Transfer stock'            => ['Move',   'Move stock between stores, e.g. main store to the bar.'],
        'Adjust stock'              => ['Adjust', 'Record stock used, wasted or broken, and correct mistakes (a reason is required).'],
        'Count stock'               => ['Count',  'Start a stocktake and enter counted quantities.'],
        'Post stock counts'         => ['Approve','Approve a stocktake so the differences are booked to stock.'],
        'View attendance'           => ['View',   'See who is in, lateness, absences and timesheets for all staff.'],
        'Manage attendance'         => ['Manage', 'Correct clock-ins (with a reason), change the rota and record leave.'],
        'Manage staff & shifts'     => ['Manage', 'Set up shifts, departments, kiosks and staff badges (including replacing a lost badge).'],
        'Run attendance kiosk'      => ['Use',    'Open the clock-in kiosk on a device at the staff entrance.'],
        'Give tasks'                => ['Create', 'Give tasks to a department or a person, with a due time, photos and an optional supervisor check.'],
        'View all tasks'            => ['View',   'See every department\'s tasks, not only your own and your department\'s.'],
        'Manage tasks'              => ['Manage', 'Change, check and cancel any task, and be told when overdue tasks are escalated.'],
        'Handle guest requests'     => ['Manage', 'See and answer requests guests send from their booking page (towels, repairs, room service…).'],
        'Chat with guests'          => ['Manage', 'Answer guests\' chats and calls for the hotel sections they work in (Reception, Restaurant, Bar …).'],
        'Manage guest chat'         => ['Manage', 'See every section\'s chats and calls, and set sections up: who answers, hours, phone numbers.'],
        'Handle laundry'            => ['Manage', 'Collect and count guests\' laundry (the count goes on the room bill), mark it ready and returned, record damaged or missing pieces.'],
        'Manage laundry'            => ['Manage', 'Set the laundry price list: items, and the price of washing, dry cleaning and pressing each.'],
        'Manage wellness bookings'  => ['Manage', 'See the spa, gym and pool diary, book for guests and visitors, mark them arrived (in-house guests are charged to the room), done or didn\'t come, and cancel.'],
        'Set up wellness'           => ['Manage', 'Set up the spa, gym and pool: hours, how many people at once, treatments, therapists and prices.'],
        'Manage payroll'            => ['Manage', 'Set each person\'s pay and bank details, run the monthly payroll from attendance, add bonuses or deductions, download the bank schedule and mark it paid.'],
        'Approve payroll'           => ['Approve','Approve the monthly payroll (or send it back) — never one you sent yourself.'],
        'Message guests'            => ['Manage', 'Send SMS / WhatsApp messages to guests and read their replies.'],
        'Record expenses'           => ['Create', 'Record running costs (diesel, repairs, supplies…) with receipts. Small ones are approved automatically.'],
        'View expenses'             => ['View',   'See every expense, budgets and spending by department — not only your own.'],
        'Approve expenses'          => ['Approve','Approve or reject expenses above the approval limit (never your own), and void expenses.'],
        'Pay expenses'              => ['Pay',    'Mark approved expenses as paid — by transfer, card, cheque or cash from your till.'],
        'Manage expense categories' => ['Manage', 'Set up expense categories and their monthly budgets.'],
        'View IT control room'      => ['View',   'See the IT control room: network, systems and backup status, alerts and the service desk queue.'],
        'Manage IT monitoring'      => ['Manage', 'Set up what is monitored, the equipment register and planned maintenance; acknowledge and close alerts.'],
        'View energy saver'         => ['View',   'See the Energy Saver: empty rooms and places that can be switched off, rooms to switch on before guests arrive, and which generator to run. Tick off what was done.'],
        'Manage energy saver'       => ['Manage', 'Set up the Energy Saver: places and their power, generators and fuel use, prices; record grid and generator changes.'],
        'Manage IT tickets'         => ['Manage', 'Work on every IT support ticket: assign, update, resolve and close. (Any staff member can raise a ticket.)'],
        'View network'              => ['View', 'See the internet lines (Starlink dishes and others), their speed, signal and data used, and the guest Wi-Fi codes.'],
        'Manage network'            => ['Manage', 'Add and change internet lines and guest Wi-Fi speed plans.'],
        'Give Wi-Fi codes'          => ['Give', 'Give guests and visitors Wi-Fi codes, change their plan or end time, and cut a code off.'],
        'Use call console'          => ['View',   'Open the call console: every guest call ringing or on, missed calls, network strength, weather and the room / area equipment board.'],
        'Answer any guest call'     => ['Manage', 'Pick up any guest call from the console (with a headset) and put calls through to the right person or section.'],
        'Listen to guest calls'     => ['Manage', 'Listen in on guest calls and play guests\' voice messages. Sensitive: a reason is asked each time and every listen is in the audit trail.'],
        'Manage room equipment'     => ['Manage', 'List the equipment in rooms and other areas, switch it on or off on the board, and create keys for hardware hubs.'],
        'Manage hotel settings'     => ['Manage', 'Change hotel settings: name and logo, taxes, approval limits, cash and audit rules, and the bank accounts shown on invoices.'],
    ];

    /** ['action' => chip word, 'label' => readable name, 'desc' => sentence] */
    public static function for(string $name): array
    {
        $label = ucfirst(trim($name));

        if (isset(self::OVERRIDES[$name])) {
            [$action, $desc] = self::OVERRIDES[$name];
            return ['action' => $action, 'label' => $label, 'desc' => $desc];
        }

        $parts = explode(' ', trim($name), 2);
        $verb = ucfirst(strtolower($parts[0]));
        $rest = isset($parts[1]) ? self::pretty($parts[1]) : '';

        if (isset(self::VERBS[$verb]) && $rest !== '') {
            [$chip, $tpl] = self::VERBS[$verb];
            return ['action' => $chip, 'label' => $label, 'desc' => str_replace('{x}', $rest, $tpl)];
        }

        return ['action' => 'Access', 'label' => $label, 'desc' => 'Grants the “' . $label . '” permission.'];
    }

    public static function action(string $name): string
    {
        return self::for($name)['action'];
    }

    /** "student-report" / "online-fee-payments" => "student report / online fee payments" */
    protected static function pretty(string $s): string
    {
        return trim(strtolower(str_replace(['-', '_'], ' ', $s)));
    }

    /** Chip colour class by action word. */
    public static function chipClass(string $action): string
    {
        return [
            'View' => 'pm-chip-view', 'Preview' => 'pm-chip-view',
            'Create' => 'pm-chip-create', 'Add' => 'pm-chip-create',
            'Edit' => 'pm-chip-edit', 'Assign' => 'pm-chip-edit',
            'Manage' => 'pm-chip-manage',
            'Delete' => 'pm-chip-delete', 'Remove' => 'pm-chip-delete', 'Close' => 'pm-chip-delete',
            'Approve' => 'pm-chip-approve', 'Submit' => 'pm-chip-approve', 'Post' => 'pm-chip-approve',
            'Release' => 'pm-chip-money', 'Pay' => 'pm-chip-money',
        ][$action] ?? 'pm-chip-other';
    }
}
