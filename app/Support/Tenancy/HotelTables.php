<?php

namespace App\Support\Tenancy;

/** Tables whose rows belong to one hotel (kept in step with the 2026_11_13 migration). */
final class HotelTables
{
    public const LIST = [
        'attendance_days', 'attendance_points', 'attendance_punches', 'audit_logs', 'buildings', 'cash_shift_movements', 'cash_shifts', 'chat_conversations', 'chat_sections', 'guest_calls',
        'channel_bookings', 'channel_sync_logs', 'companies', 'company_ledger_entries', 'departments', 'event_items', 'event_packages',
        'event_payments', 'events', 'expense_categories', 'expenses', 'floors', 'folio_charges', 'guest_arrivals', 'folios', 'guest_messages', 'guest_orders', 'guest_requests',
        'energy_actions', 'energy_events', 'energy_fuel_deliveries', 'energy_samples', 'energy_sources', 'energy_zones', 'guests', 'halls', 'hotel_bank_accounts', 'housekeeping_tasks', 'ical_feeds', 'incident_updates', 'incidents', 'it_alerts', 'it_assets',
        'it_checks', 'it_maintenance_windows', 'it_monitors', 'it_ticket_updates', 'it_tickets', 'lost_items', 'loyalty_entries',
        'maintenance_orders', 'night_audits', 'online_payments', 'payments', 'pos_categories', 'pos_item_components', 'pos_items',
        'pos_order_items', 'pos_orders', 'pos_outlets', 'purchase_order_lines', 'purchase_orders', 'purchase_request_lines',
        'purchase_requests', 'rate_days', 'rate_plans', 'rate_seasons', 'reservation_events', 'reservation_rooms', 'reservations',
        'room_status_logs', 'room_type_photos', 'room_types', 'rooms', 'shifts', 'staff_leaves', 'staff_profiles', 'staff_rota',
        'stock_categories', 'stock_count_lines', 'stock_counts', 'stock_items', 'stock_levels', 'stock_locations', 'stock_movements',
        'stock_receipt_lines', 'stock_receipts', 'stock_transfer_lines', 'stock_transfers', 'supplier_invoices', 'supplier_payments',
        'suppliers', 'visitors', 'vehicles', 'drivers', 'ride_places', 'rides', 'staff_tasks', 'staff_task_updates',
        'event_ticket_types', 'event_ticket_orders', 'event_tickets', 'website_settings', 'website_items', 'website_messages', 'website_subscribers',
        'network_links', 'network_samples', 'network_plans', 'network_passes',
        'call_listens', 'facility_devices', 'facility_hubs',
        'guest_feedback', 'maintenance_plans',
        'event_ticket_promos', 'laundry_items', 'laundry_orders',
        'wellness_venues', 'wellness_services', 'wellness_therapists', 'wellness_bookings',
        'staff_pay', 'payroll_runs', 'payslips',
        'room_keys', 'door_events',
        'payment_terminals', 'terminal_payments', 'vehicle_logs',
    ];
}
