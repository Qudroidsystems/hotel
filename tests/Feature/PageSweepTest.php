<?php

use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Opens every page of the website (GET routes without a form to fill) on the full demo hotel, as the Super Admin
 * and as each role, and fails on any server error. Route parameters are filled with real records of the type the
 * controller asks for.
 */
function sweepUrls(): array
{
    $urls = [];
    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $uri = $route->uri();
        if (str_starts_with($uri, 'api/') || str_starts_with($uri, '_') || str_contains($uri, 'sanctum') || in_array($uri, ['up', 'logout'], true)) {
            continue;
        }
        $params = [];
        $types = [];
        $action = $route->getAction('uses');
        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action);
            if (method_exists($class, $method)) {
                foreach ((new ReflectionMethod($class, $method))->getParameters() as $p) {
                    $t = $p->getType();
                    if ($t instanceof ReflectionNamedType && ! $t->isBuiltin() && is_subclass_of($t->getName(), Illuminate\Database\Eloquent\Model::class)) {
                        $types[$p->getName()] = $t->getName();
                    }
                }
            }
        }
        $ok = true;
        foreach ($route->parameterNames() as $name) {
            if (isset($types[$name])) {
                $m = $types[$name]::query()->first();
                if (! $m) {
                    $ok = false;
                    break;
                }
                $params[$name] = $m->getRouteKey();
            } elseif ($name === 'hotelSlug') {
                $params[$name] = App\Models\Hotel::first()->slug;
            } elseif ($name === 'token' && str_contains($uri, 'stay')) {
                $params[$name] = Reservation::whereNotNull('guest_token')->value('guest_token') ?? 'x';
            } elseif ($name === 'token' && str_starts_with($uri, 'r/')) {
                $params[$name] = Reservation::value('qr_token');
            } elseif ($name === 'token' && str_starts_with($uri, 'ical')) {
                $params[$name] = App\Models\Channels\IcalFeed::value('export_token') ?? 'none';
            } elseif ($name === 'id' && $route->getName() === 'settings') {
                $params[$name] = User::first()->id;
            } elseif ($name === 'id') {
                $params[$name] = 1;
            } else {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            try {
                $urls[] = ['url' => route($route->getName() ?? '', $params, false), 'name' => $route->getName() ?? $uri];
            } catch (Throwable) {
                $urls[] = ['url' => '/' . preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $params[$m[1]] ?? '', $uri), 'name' => $uri];
            }
        }
    }

    return $urls;
}

beforeEach(function () {
    \Illuminate\Support\Facades\Mail::fake();
    \Illuminate\Support\Facades\Notification::fake();
    \Illuminate\Support\Facades\Http::fake();
    $this->seed(DemoSeeder::class);
    $this->withoutDefer();
});

test('every page opens for the Super Admin without a server error', function () {
    $admin = User::role('Super Admin')->first();
    $admin->forceFill(['must_change_password' => false])->save();
    $this->actingAs($admin);
    $broken = [];
    $codes = [];
    $urls = sweepUrls();
    expect(count($urls))->toBeGreaterThan(120);
    foreach ($urls as $u) {
        $res = $this->get($u['url']);
        $codes[$res->getStatusCode()][] = $u['url'];
        if ($res->getStatusCode() >= 500) {
            $broken[] = $u['url'] . ' (' . $u['name'] . '): ' . ($res->exception ? class_basename($res->exception) . ' ' . str($res->exception->getMessage())->limit(200) : $res->getStatusCode());
        }
    }
    if (getenv('SWEEP_DEBUG')) {
        fwrite(STDERR, json_encode(array_map(fn ($l) => count($l) . ' ' . implode(' ', array_slice($l, 0, 40)), $codes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    expect($broken)->toBe([]);
});

test('every page either opens or says no for each role, never a server error', function () {
    $broken = [];
    $roles = Spatie\Permission\Models\Role::where('name', '!=', 'Super Admin')->pluck('name');
    $urls = sweepUrls();
    foreach ($roles as $role) {
        $user = User::role($role)->first();
        if (! $user) {
            continue;
        }
        $user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($user);
        foreach ($urls as $u) {
            $res = $this->get($u['url']);
            if ($res->getStatusCode() >= 500) {
                $broken[] = "[{$role}] " . $u['url'] . ': ' . ($res->exception ? class_basename($res->exception) . ' ' . str($res->exception->getMessage())->limit(160) : $res->getStatusCode());
            }
        }
    }
    expect($broken)->toBe([]);
});

test('with several hotels, every page opens in the second hotel too', function () {
    $this->seed(\Database\Seeders\MultiHotelDemoSeeder::class);
    $abuja = App\Models\Hotel::where('slug', 'b-luxury-abuja')->first();
    $broken = [];
    foreach ([User::role('Super Admin')->first(), User::where('username', 'zainab.abuja')->first()] as $user) {
        $user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($user)->withSession(['hotel_id' => $abuja->id]);
        // Records for the route parameters come from the Abuja hotel.
        $urls = App\Support\Tenancy\CurrentHotel::get()->run($abuja, fn () => sweepUrls());
        foreach ($urls as $u) {
            $res = $this->get($u['url']);
            if ($res->getStatusCode() >= 500) {
                $broken[] = "[{$user->name}] " . $u['url'] . ': ' . ($res->exception ? class_basename($res->exception) . ' ' . str($res->exception->getMessage())->limit(160) : $res->getStatusCode());
            }
        }
    }
    expect($broken)->toBe([]);
});

test('every staff app call answers without a server error, for each role', function () {
    if (! class_exists(\Laravel\Sanctum\Sanctum::class)) {
        $this->markTestSkipped('Laravel Sanctum is not installed.');
    }
    $calls = ['app/config', 'auth/me', 'me/profile', 'me/preferences', 'home', 'work', 'approvals', 'front-desk', 'rooms', 'report/options',
        'notifications', 'notifications/unread-count', 'notifications/settings', 'night-audit', 'events', 'events?when=upcoming', 'companies', 'group',
        'guest/booking', 'guest/search?check_in=' . today()->addDays(9)->toDateString() . '&check_out=' . today()->addDays(11)->toDateString(), 'guest/hotels'];
    $event = App\Models\Events\Event::value('id');
    $audit = App\Models\NightAudit::value('id');
    if ($event) {
        $calls[] = "events/{$event}";
    }
    if ($audit) {
        $calls[] = "night-audit/{$audit}";
    }
    $broken = [];
    foreach (Spatie\Permission\Models\Role::pluck('name') as $role) {
        $user = User::role($role)->first();
        if (! $user) {
            continue;
        }
        $user->forceFill(['must_change_password' => false])->save();
        app('auth')->forgetGuards();
        $token = $user->createToken('sweep')->plainTextToken;
        foreach ($calls as $c) {
            app('auth')->forgetGuards();
            $res = $this->withToken($token)->getJson('/api/v1/' . $c);
            if ($res->getStatusCode() >= 500 || ($res->getStatusCode() < 300 && $res->json('success') !== true)) {
                $broken[] = "[{$role}] {$c}: " . $res->getStatusCode() . ' ' . str(json_encode($res->json()))->limit(160);
            }
        }
    }
    expect($broken)->toBe([]);
});
