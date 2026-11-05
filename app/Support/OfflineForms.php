<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Which website forms may be kept on the computer while the hotel system
 * can't be reached, and sent later (each exactly once, see the
 * OfflineReplay middleware), as patterns the page's script matches form
 * addresses against.
 */
class OfflineForms
{
    /**
     * Work that carries on with no connection: route names, * matches the rest.
     * Anything else (money, check-in/out, settings) waits for the connection.
     */
    public const ROUTES = [
        'housekeeping.start', 'housekeeping.finish', 'housekeeping.inspect', 'housekeeping.assign',
        'maintenance.store', 'maintenance.start', 'maintenance.complete', 'maintenance.hold', 'maintenance.assign',
        'guest-requests.update', 'guest-requests.maintenance',
        'tasks.store', 'tasks.start', 'tasks.take', 'tasks.finish', 'tasks.comment', 'tasks.check',
        'laundry.collect', 'laundry.act', 'wellness.act', 'pos.room-orders.act',
        'incidents.store', 'incidents.updates.store', 'lost-found.store', 'lost-found.hand-over',
        'visitors.store', 'visitors.sign-out', 'it.tickets.store', 'it.tickets.update',
        'energy.done', 'energy.skip', 'energy.running', 'energy.fuel', 'energy.grid',
        'events.tickets.admit', 'events.check-in', 'events.check-out',
        'transport.rides.status',
    ];

    private static ?array $patterns = null;

    /** @return string[] JavaScript regular expressions for the paths (without the domain). */
    public static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }
        $names = self::ROUTES;
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! array_intersect($route->methods(), ['POST', 'PUT', 'PATCH']) || ! Str::is($names, $name)) {
                continue;
            }
            $base = rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/');
            $uri = preg_quote('/' . ltrim($route->uri(), '/'), '#');
            // {param} and {param?} match one path segment.
            $uri = preg_replace('#\\\\\{[^/]+?\\\\\}#', '[^/]+', $uri);
            $out[] = '^' . str_replace('#', '\\#', preg_quote($base, '#')) . $uri . '$';
        }

        return self::$patterns = array_values(array_unique($out));
    }
}
