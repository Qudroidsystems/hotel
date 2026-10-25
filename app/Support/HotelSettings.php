<?php

namespace App\Support;

use App\Models\HotelSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hotel settings the administrator can change from Settings → Hotel Settings.
 *
 * config/hotel.php (and the HOTEL_* values in .env) hold the defaults. Values
 * saved on the settings page live in the `hotel_settings` table and are laid
 * over config('hotel.*') when the app boots, so every existing
 * config('hotel.procurement.approval_limit') call picks up the saved value
 * with no other code changes. Removing a saved value ("Use default") falls
 * back to config/.env again.
 *
 * Each key is the setting's path inside config('hotel'), e.g.
 * "procurement.approval_limit".
 */
class HotelSettings
{
    public const CACHE_KEY = 'hotel_settings.values';

    /** config('hotel') as loaded from the file, before saved values were applied. */
    private static ?array $defaults = null;

    /**
     * group key => [title, icon, intro, fields]
     * field: type (text|number|money|percent|int|bool|list|int_list|image|currency),
     *        label, help, rules (extra validation), min/max/step for numbers.
     */
    public static function groups(): array
    {
        return [
            'branding' => [
                'title' => 'Branding', 'icon' => 'ri-palette-line',
                'intro' => 'The hotel name, tagline and logos shown on the sign-in page, sidebar, invoices, receipts and page titles.',
                'fields' => [
                    'name'       => ['type' => 'text', 'label' => 'Hotel name', 'rules' => ['required', 'string', 'max:100']],
                    'tagline'    => ['type' => 'text', 'label' => 'Tagline', 'rules' => ['nullable', 'string', 'max:150'], 'help' => 'Shown under the name on the sign-in page and printouts.'],
                    'logo'       => ['type' => 'image', 'label' => 'Logo (dark, for light backgrounds)', 'help' => 'PNG, JPG or WebP, up to 1 MB. A wide logo about 300 × 80 px works best.'],
                    'logo_light' => ['type' => 'image', 'label' => 'Logo (light, for dark backgrounds)', 'help' => 'Used on the dark sidebar.'],
                    'favicon'    => ['type' => 'image', 'label' => 'Browser tab icon', 'help' => 'A square PNG or ICO, 32 × 32 or 64 × 64 px.', 'mimes' => 'png,ico,jpg,jpeg,webp'],
                ],
            ],
            'booking' => [
                'title' => 'Online Booking', 'icon' => 'ri-global-line',
                'intro' => 'The public booking page (/book) and the guest\'s own booking page. Rates and availability come from your room types and rooms.',
                'fields' => [
                    'booking.enabled'         => ['type' => 'bool', 'label' => 'Take bookings online', 'help' => 'Off: the booking page shows your contact details instead of a booking form. Guests can still open their existing bookings.'],
                    'booking.welcome'         => ['type' => 'text', 'label' => 'Welcome line on the booking page', 'rules' => ['nullable', 'string', 'max:160'], 'help' => 'One sentence under the hotel name, e.g. "Calm rooms ten minutes from the airport."'],
                    'booking.deposit_percent' => ['type' => 'percent', 'label' => 'Deposit asked online', 'help' => 'Share of the stay paid when booking (Paystack or bank transfer). 0 = guests pay at the hotel and bookings are confirmed straight away. 100 = full prepayment.'],
                    'booking.hold_hours'      => ['type' => 'int', 'label' => 'Release unpaid bookings after (hours)', 'min' => 1, 'max' => 336, 'help' => 'A booking waiting for its deposit is cancelled automatically after this, so the room goes back on sale.'],
                    'booking.cancel_hours'    => ['type' => 'int', 'label' => 'Guests can cancel online until (hours before check-in)', 'min' => 0, 'max' => 720, 'help' => 'After this the guest must call the hotel. 0 = until check-in time.'],
                    'booking.max_nights'      => ['type' => 'int', 'label' => 'Longest stay bookable online (nights)', 'min' => 1, 'max' => 365],
                    'booking.max_rooms'       => ['type' => 'int', 'label' => 'Most rooms in one online booking', 'min' => 1, 'max' => 20],
                    'booking.advance_days'    => ['type' => 'int', 'label' => 'How far ahead guests can book (days)', 'min' => 7, 'max' => 730],
                    'booking.check_in_time'   => ['type' => 'text', 'label' => 'Check-in time', 'rules' => ['required', 'date_format:H:i']],
                    'booking.check_out_time'  => ['type' => 'text', 'label' => 'Check-out time', 'rules' => ['required', 'date_format:H:i']],
                    'booking.contact_phone'   => ['type' => 'text', 'label' => 'Reservations phone', 'rules' => ['nullable', 'string', 'max:40']],
                    'booking.contact_email'   => ['type' => 'text', 'label' => 'Reservations email', 'rules' => ['nullable', 'email', 'max:150']],
                    'booking.address'         => ['type' => 'text', 'label' => 'Hotel address', 'rules' => ['nullable', 'string', 'max:255']],
                    'booking.policies'        => ['type' => 'list', 'label' => 'Hotel policies shown to guests', 'help' => 'One per line.', 'max_items' => 15],
                    'booking.guest_requests'  => ['type' => 'bool', 'label' => 'Guests can send requests (towels, cleaning, repairs…) from their booking page during their stay'],
                ],
            ],
            'messaging' => [
                'title' => 'Guest Messages', 'icon' => 'ri-message-3-line',
                'intro' => 'Text messages to guests by SMS or WhatsApp through Twilio. The Twilio account (SID, token and numbers) is set in the server\'s .env file by IT. Guests who asked not to be texted are skipped.',
                'fields' => [
                    'messaging.enabled'      => ['type' => 'bool', 'label' => 'Send text messages to guests'],
                    'messaging.channel'      => ['type' => 'select', 'label' => 'Send by', 'options' => ['sms' => 'SMS', 'whatsapp' => 'WhatsApp (needs an approved WhatsApp sender)']],
                    'messaging.country_code' => ['type' => 'text', 'label' => 'Country code for local numbers', 'rules' => ['required', 'regex:/^[0-9]{1,4}$/'], 'help' => 'Digits only, e.g. 234. A number written 0803 123 4567 is sent to +234 803 123 4567.'],
                    'messaging.sender_name'  => ['type' => 'text', 'label' => 'SMS sender name', 'rules' => ['nullable', 'regex:/^[A-Za-z0-9 ]{1,11}$/'], 'help' => 'Up to 11 letters or digits, where the mobile networks allow it. Empty: the Twilio number.'],
                    'messaging.send_confirmation'    => ['type' => 'bool', 'label' => 'When a booking is confirmed'],
                    'messaging.send_payment_link'    => ['type' => 'bool', 'label' => 'When an online booking waits for its deposit (with the payment link)'],
                    'messaging.send_reminder'        => ['type' => 'bool', 'label' => 'The day before arrival'],
                    'messaging.reminder_time'        => ['type' => 'text', 'label' => 'Send arrival reminders at', 'rules' => ['required', 'date_format:H:i']],
                    'messaging.send_thank_you'       => ['type' => 'bool', 'label' => 'After check-out (thank you, with their points)'],
                    'messaging.send_request_updates' => ['type' => 'bool', 'label' => 'When staff answer a guest request'],
                    'messaging.templates.booking_confirmed' => ['type' => 'textarea', 'label' => 'Booking confirmed', 'rules' => ['required', 'string', 'max:640'], 'help' => 'Words in {braces} are filled in: {first_name} {guest} {hotel} {reference} {check_in} {check_out} {nights} {link} {amount} {points} {phone}.'],
                    'messaging.templates.payment_link'      => ['type' => 'textarea', 'label' => 'Deposit needed', 'rules' => ['required', 'string', 'max:640']],
                    'messaging.templates.arrival_reminder'  => ['type' => 'textarea', 'label' => 'Arrival reminder', 'rules' => ['required', 'string', 'max:640'], 'help' => 'Also {check_in_time}.'],
                    'messaging.templates.thank_you'         => ['type' => 'textarea', 'label' => 'Thank you after check-out', 'rules' => ['required', 'string', 'max:640']],
                    'messaging.templates.request_update'    => ['type' => 'textarea', 'label' => 'Guest request answered', 'rules' => ['required', 'string', 'max:640'], 'help' => 'Also {request}, {status} and {note}.'],
                ],
            ],
            'loyalty' => [
                'title' => 'Guest Loyalty', 'icon' => 'ri-vip-diamond-line',
                'intro' => 'Points guests earn on every settled stay and spend on a later bill, and member tiers from nights stayed in the last 12 months. Points are earned when the bill is fully paid after check-out.',
                'fields' => [
                    'loyalty.enabled'         => ['type' => 'bool', 'label' => 'Run the loyalty programme'],
                    'loyalty.prefix'          => ['type' => 'text', 'label' => 'Member number starts with', 'rules' => ['required', 'string', 'alpha_num', 'max:6'], 'help' => 'e.g. SH gives member numbers like SH000123.'],
                    'loyalty.points_per_1000' => ['type' => 'int', 'label' => 'Points earned per 1,000 spent', 'min' => 0, 'max' => 1000, 'help' => 'Counted on the bill before tax, after discounts.'],
                    'loyalty.point_value'     => ['type' => 'money', 'label' => 'Value of one point when spent'],
                    'loyalty.min_redeem'      => ['type' => 'int', 'label' => 'Fewest points spent at once', 'min' => 1, 'max' => 1000000],
                    'loyalty.silver_nights'   => ['type' => 'int', 'label' => 'Silver: nights in the last 12 months', 'min' => 1, 'max' => 365],
                    'loyalty.gold_nights'     => ['type' => 'int', 'label' => 'Gold: nights in the last 12 months', 'min' => 1, 'max' => 365],
                    'loyalty.silver_discount' => ['type' => 'percent', 'label' => 'Silver discount on rooms booked online', 'max' => 50],
                    'loyalty.gold_discount'   => ['type' => 'percent', 'label' => 'Gold discount on rooms booked online', 'max' => 50],
                ],
            ],
            'money' => [
                'title' => 'Currency & Tax', 'icon' => 'ri-percent-line',
                'intro' => 'How amounts are shown, and the taxes and service charge on guest bills and bar sales. Changes apply to new charges only; bills already posted keep the tax they were charged.',
                'fields' => [
                    'currency'                  => ['type' => 'currency', 'label' => 'Currency code', 'rules' => ['required', 'string', 'size:3', 'alpha'], 'help' => 'Three letters, e.g. NGN, USD.'],
                    'currency_symbol'           => ['type' => 'text', 'label' => 'Currency symbol', 'rules' => ['required', 'string', 'max:5']],
                    'prices_include_tax'        => ['type' => 'bool', 'label' => 'Room rates and menu prices already include tax', 'help' => 'On: the invoice shows the tax inside each price. Off: tax is added on top.'],
                    'tax_rates.vat'             => ['type' => 'percent', 'label' => 'VAT', 'help' => 'Set to 0 to switch it off.'],
                    'tax_rates.service_charge'  => ['type' => 'percent', 'label' => 'Service charge'],
                    'tax_rates.consumption'     => ['type' => 'percent', 'label' => 'Consumption tax', 'help' => 'State consumption / hotel occupancy tax, where it applies.'],
                ],
            ],
            'operations' => [
                'title' => 'Rooms & Housekeeping', 'icon' => 'ri-hotel-bed-line',
                'intro' => 'Room amenities and how cleaned rooms are released.',
                'fields' => [
                    'housekeeping.require_inspection' => ['type' => 'bool', 'label' => 'A supervisor must inspect a cleaned room before it can be sold'],
                    'amenities'                       => ['type' => 'list', 'label' => 'Amenities offered on room types', 'help' => 'One per line. These are the tick boxes on the room type form.', 'max_items' => 60],
                ],
            ],
            'cash' => [
                'title' => 'Cashier Shifts', 'icon' => 'ri-safe-2-line',
                'intro' => 'Cash-up rules at the bar, restaurant and front desk.',
                'fields' => [
                    'cash.variance_tolerance' => ['type' => 'money', 'label' => 'Allowed cash difference at close', 'help' => 'A count further off than this from the expected cash needs an explanation and is flagged on the audit trail.'],
                    'cash.stations'           => ['type' => 'list', 'label' => 'Stations', 'help' => 'One per line — suggestions for "where are you working" when a shift opens.', 'max_items' => 30],
                    'cash.denominations'      => ['type' => 'int_list', 'label' => 'Notes counted at close', 'help' => 'One per line, largest first, e.g. 1000, 500, 200.', 'max_items' => 20],
                ],
            ],
            'procurement' => [
                'title' => 'Procurement', 'icon' => 'ri-shopping-cart-2-line',
                'intro' => 'Approval and checking rules for purchase orders and supplier invoices.',
                'fields' => [
                    'procurement.approval_limit'     => ['type' => 'money', 'label' => 'Purchase order approval limit', 'help' => 'Orders worth more than this need approval by someone other than the person who raised them. 0 means every order needs approval.'],
                    'procurement.price_jump_percent' => ['type' => 'percent', 'label' => 'Highlight price rises above', 'max' => 1000, 'help' => 'A price this much higher than the last price paid is highlighted on orders and receipts.'],
                    'procurement.invoice_tolerance'  => ['type' => 'money', 'label' => 'Supplier invoice tolerance', 'help' => 'How far an invoice may differ from the value of goods received before it is flagged.'],
                ],
            ],
            'expenses' => [
                'title' => 'Expenses', 'icon' => 'ri-wallet-3-line',
                'intro' => 'Approval rule and departments (cost centres) for running costs.',
                'fields' => [
                    'expenses.approval_limit' => ['type' => 'money', 'label' => 'Expense approval limit', 'help' => 'Expenses above this need approval by someone other than the person who recorded them. 0 means every expense needs approval.'],
                    'expenses.departments'    => ['type' => 'list', 'label' => 'Departments', 'help' => 'One per line. Expenses are charged to one of these, so running costs can be compared by department.', 'max_items' => 30],
                ],
            ],
            'inventory' => [
                'title' => 'Stock Analysis', 'icon' => 'ri-archive-line',
                'intro' => 'Used by the stock health and reorder reports.',
                'fields' => [
                    'inventory.usage_days'       => ['type' => 'int', 'label' => 'Average usage over the last (days)', 'min' => 7, 'max' => 365, 'help' => 'Daily usage is averaged over this many days.'],
                    'inventory.cover_days'       => ['type' => 'int', 'label' => 'Days of stock to keep', 'min' => 1, 'max' => 180, 'help' => 'Suggested orders aim to cover this many days of usage plus the reorder level.'],
                    'inventory.slow_moving_days' => ['type' => 'int', 'label' => 'Slow-moving after (days without use)', 'min' => 7, 'max' => 730],
                ],
            ],
            'it' => [
                'title' => 'IT Service Desk', 'icon' => 'ri-customer-service-2-line',
                'intro' => 'Response and fix targets for IT tickets, and who is emailed about outages. Tickets that miss a target are escalated automatically.',
                'fields' => [
                    'it.response_minutes.critical' => ['type' => 'int', 'label' => 'Critical — respond within (minutes)', 'min' => 1, 'max' => 10080],
                    'it.resolve_hours.critical'    => ['type' => 'int', 'label' => 'Critical — fix within (hours)', 'min' => 1, 'max' => 2160],
                    'it.response_minutes.high'     => ['type' => 'int', 'label' => 'High — respond within (minutes)', 'min' => 1, 'max' => 10080],
                    'it.resolve_hours.high'        => ['type' => 'int', 'label' => 'High — fix within (hours)', 'min' => 1, 'max' => 2160],
                    'it.response_minutes.normal'   => ['type' => 'int', 'label' => 'Normal — respond within (minutes)', 'min' => 1, 'max' => 10080],
                    'it.resolve_hours.normal'      => ['type' => 'int', 'label' => 'Normal — fix within (hours)', 'min' => 1, 'max' => 2160],
                    'it.response_minutes.low'      => ['type' => 'int', 'label' => 'Low — respond within (minutes)', 'min' => 1, 'max' => 10080],
                    'it.resolve_hours.low'         => ['type' => 'int', 'label' => 'Low — fix within (hours)', 'min' => 1, 'max' => 2160],
                    'it.alert_emails'              => ['type' => 'email_list', 'label' => 'Email outages and escalations to', 'help' => 'One email address per line. Critical and high alerts, and overdue tickets, are emailed here. Leave empty to only show them in the control room.', 'max_items' => 10],
                    'it.check_retention_days'      => ['type' => 'int', 'label' => 'Keep monitoring history for (days)', 'min' => 7, 'max' => 730],
                ],
            ],
            'security' => [
                'title' => 'Security & Visitors', 'icon' => 'ri-shield-user-line',
                'intro' => 'Visitor passes, privacy and lost property.',
                'fields' => [
                    'security.pass_hours'     => ['type' => 'int', 'label' => 'Visitor pass lasts (hours)', 'min' => 1, 'max' => 72],
                    'security.retention_days' => ['type' => 'int', 'label' => 'Clear visitor personal details after (days)', 'min' => 7, 'max' => 3650, 'help' => 'Phone, ID number and plate of visitors who left longer ago than this are cleared every night.'],
                    'security.lost_item_days' => ['type' => 'int', 'label' => 'Unclaimed lost items can be disposed of after (days)', 'min' => 1, 'max' => 3650],
                ],
            ],
            'audit' => [
                'title' => 'Audit Alerts', 'icon' => 'ri-alarm-warning-line',
                'intro' => 'When the audit trail flags something for a manager to review.',
                'fields' => [
                    'audit.flag_amount'   => ['type' => 'money', 'label' => 'Flag voids, refunds and discounts from', 'help' => 'Any void, refund or discount of this amount or more is flagged.'],
                    'audit.flag_failures' => ['type' => 'int', 'label' => 'Flag failed sign-ins / QR scans after', 'min' => 2, 'max' => 50, 'help' => 'Failures from the same person or device within 15 minutes.'],
                ],
            ],
        ];
    }

    /** key => field definition (with its group). */
    public static function fields(): array
    {
        $out = [];
        foreach (self::groups() as $group => $g) {
            foreach ($g['fields'] as $key => $field) {
                $out[$key] = $field + ['group' => $group, 'key' => $key];
            }
        }

        return $out;
    }

    /** Validation rules for one group's form. Input names use "__" in place of dots. */
    public static function rules(string $group): array
    {
        $rules = [];
        foreach (self::groups()[$group]['fields'] as $key => $f) {
            $name = self::input($key);
            $rules[$name] = match ($f['type']) {
                'bool'     => ['nullable', 'boolean'],
                'money'    => ['required', 'numeric', 'min:0', 'max:1000000000'],
                'percent'  => ['required', 'numeric', 'min:0', 'max:' . ($f['max'] ?? 100)],
                'int'      => ['required', 'integer', 'min:' . ($f['min'] ?? 0), 'max:' . ($f['max'] ?? 100000)],
                'list', 'int_list' => ['nullable', 'string', 'max:5000'],
                'email_list' => ['nullable', 'string', 'max:2000', function ($attribute, $value, $fail) {
                    foreach (preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $email) {
                        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $fail('"' . $email . '" is not a valid email address.');
                        }
                    }
                }],
                'image'    => ['nullable', 'file', 'mimes:' . ($f['mimes'] ?? 'png,jpg,jpeg,webp'), 'max:1024'],
                'select'   => ['required', 'in:' . implode(',', array_keys($f['options']))],
                'textarea' => $f['rules'] ?? ['nullable', 'string', 'max:1000'],
                default    => $f['rules'] ?? ['nullable', 'string', 'max:255'],
            };
        }

        return $rules;
    }

    /** Turn submitted form input into the value stored for a field. */
    public static function cast(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'bool'     => (bool) $value,
            'money', 'percent' => round((float) $value, 2),
            'int'      => (int) $value,
            'currency' => strtoupper(trim((string) $value)),
            'list'     => collect(preg_split('/\r\n|\r|\n/', (string) $value))->map(fn ($l) => trim($l))->filter()->unique()->take($field['max_items'] ?? 50)->values()->all(),
            'email_list' => collect(preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY))->map(fn ($e) => strtolower(trim($e)))->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->take($field['max_items'] ?? 10)->values()->all(),
            'int_list' => collect(preg_split('/[\s,]+/', (string) $value))->map(fn ($l) => (int) preg_replace('/\D/', '', $l))->filter(fn ($n) => $n > 0)->unique()->sortDesc()->take($field['max_items'] ?? 20)->values()->all(),
            default    => is_string($value) ? trim($value) : $value,
        };
    }

    public static function input(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** Saved values: key => value. Cached until a setting changes. */
    public static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => HotelSetting::pluck('value', 'key')->all());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** The config/.env value for a setting, ignoring anything saved. */
    public static function default(string $key): mixed
    {
        return Arr::get(self::$defaults ?? config('hotel'), $key);
    }

    public static function isCustom(string $key): bool
    {
        return array_key_exists($key, self::stored());
    }

    /**
     * Lay saved values over config('hotel'). Called once at boot. Never breaks
     * the app: before the table is migrated (or if the database is down) the
     * config/.env values are used as they are.
     */
    public static function apply(): void
    {
        self::$defaults ??= config('hotel', []);

        try {
            $stored = self::stored();
        } catch (Throwable $e) {
            if (! app()->runningInConsole()) {
                Log::warning('Hotel settings not loaded, using config defaults: ' . $e->getMessage());
            }

            return;
        }

        $config = self::$defaults;
        $fields = self::fields();
        foreach ($stored as $key => $value) {
            if (isset($fields[$key]) && $value !== null) {
                Arr::set($config, $key, $value);
            }
        }
        $config['taxes'] = self::taxes($config['tax_rates'] ?? []);

        config(['hotel' => $config]);
    }

    /** The tax list used on bills, rebuilt from the individual rates (0 = off). */
    public static function taxes(array $rates): array
    {
        $names = ['vat' => 'VAT', 'service_charge' => 'Service charge', 'consumption' => 'Consumption tax'];

        return collect($names)
            ->map(fn ($name, $k) => ['name' => $name, 'rate' => (float) ($rates[$k] ?? 0)])
            ->filter(fn ($t) => $t['rate'] > 0)->values()->all();
    }

    /** A value as shown in the form / audit trail. */
    public static function display(array $field, mixed $value): string
    {
        return match ($field['type']) {
            'bool'     => $value ? 'Yes' : 'No',
            'money'    => config('hotel.currency_symbol') . number_format((float) $value, 2),
            'percent'  => rtrim(rtrim(number_format((float) $value, 2), '0'), '.') . '%',
            'list', 'int_list', 'email_list' => implode(', ', (array) $value),
            'select'   => (string) ($field['options'][$value] ?? $value),
            default    => (string) $value,
        };
    }
}
