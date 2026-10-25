<?php

use App\Models\Finance\ExpenseCategory;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! class_exists(\Laravel\Sanctum\Sanctum::class)) {
        $this->markTestSkipped('Laravel Sanctum is not installed.');
    }
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class]);
    auth()->logout();
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'username' => str($name)->slug('.'), 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->cleaner = $make('Bisi Cleaner', 'Housekeeping');
    $this->desk = $make('Ada Desk', 'Front Desk');
    $this->gm = $make('Gbenga Manager', 'General Manager');
    $this->accountant = $make('Funmi Accounts', 'Accountant');
    $this->token = fn (User $u) => $this->postJson('/api/v1/auth/login', ['login' => $u->username, 'password' => 'Secret@123'])->json('data.token');
    // Each call as a fresh phone request: no remembered user or token header from the call before.
    $this->as = function (?string $token) {
        app('auth')->forgetGuards();
        $this->flushHeaders();

        return $token ? $this->withToken($token) : $this;
    };
});

test('the app checks the address, signs in with a username and refuses a wrong password', function () {
    $this->getJson('/api/v1/app/config')->assertOk()->assertJsonPath('data.api_version', 1)->assertJsonPath('data.name', config('hotel.name'));
    $this->postJson('/api/v1/auth/login', ['login' => 'bisi.cleaner', 'password' => 'nope'])->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');

    $res = $this->postJson('/api/v1/auth/login', ['login' => 'bisi.cleaner', 'password' => 'Secret@123', 'push_token' => 'tok-1', 'platform' => 'android'])->assertOk();
    expect($res->json('data.user.modules'))->toContain('work')->not->toContain('approvals')
        ->and(\App\Models\MobileDevice::where('push_token', 'tok-1')->value('user_id'))->toBe($this->cleaner->id);

    ($this->as)($res->json('data.token'))->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.counts.approvals', 0);
    ($this->as)(null)->getJson('/api/v1/home')->assertStatus(401);
});

test('a cleaner starts and finishes a room from the app, and may not touch approvals', function () {
    $room = Room::first();
    $task = HousekeepingTask::create(['room_id' => $room->id, 'type' => 'checkout']);
    $t = ($this->token)($this->cleaner);

    $item = collect(($this->as)($t)->getJson('/api/v1/work')->assertOk()->json('data'))->firstWhere('key', "housekeeping:{$task->id}");
    expect(collect($item['actions'])->pluck('id')->all())->toBe(['start']);

    ($this->as)($t)->postJson("/api/v1/items/housekeeping/{$task->id}/actions/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
    ($this->as)($t)->postJson("/api/v1/items/housekeeping/{$task->id}/actions/start")->assertStatus(422);
    ($this->as)($t)->postJson("/api/v1/items/housekeeping/{$task->id}/actions/finish")->assertOk();
    expect($task->fresh()->status)->toBe(HousekeepingTask::DONE);

    ($this->as)($t)->getJson('/api/v1/approvals')->assertStatus(403);
    ($this->as)(($this->token)($this->desk))->postJson("/api/v1/items/housekeeping/{$task->id}/actions/pass")->assertStatus(403);
});

test('an expense alerts the approver, who rejects it from the alert with a reason', function () {
    $this->actingAs($this->accountant);
    $e = app(ExpenseService::class)->record(['category_id' => ExpenseCategory::first()->id, 'description' => 'Diesel', 'amount' => 90000,
        'expense_date' => today()->toDateString()], [], $this->accountant);
    auth()->logout();
    expect($e->status)->toBe('pending');

    $t = ($this->token)($this->gm);
    $alert = ($this->as)($t)->getJson('/api/v1/notifications')->assertOk()->json('data.0');
    expect($alert['data']['kind'])->toBe('expense')->and(collect($alert['actions'])->pluck('id')->all())->toBe(['approve', 'reject']);

    ($this->as)($t)->postJson("/api/v1/notifications/{$alert['id']}/actions/reject")->assertStatus(422);
    ($this->as)($t)->postJson("/api/v1/notifications/{$alert['id']}/actions/reject", ['input' => 'Need a quote'])
        ->assertOk()->assertJsonPath('data.done', 'Rejected')->assertJsonPath('data.actions', []);
    expect($e->fresh()->status)->toBe('rejected');

    // The person who recorded it never gets the alert, and can't approve their own.
    expect($this->accountant->notifications()->count())->toBe(0);
});

test('a temporary password must be changed first, and a disabled account is signed out', function () {
    $this->desk->forceFill(['must_change_password' => true])->save();
    $t = ($this->token)($this->desk);
    ($this->as)($t)->getJson('/api/v1/home')->assertStatus(403)->assertJsonPath('code', 'password_change_required');
    ($this->as)($t)->putJson('/api/v1/auth/password', ['password' => 'Brand@New1', 'password_confirmation' => 'Brand@New1'])->assertOk();
    ($this->as)($t)->getJson('/api/v1/home')->assertOk();

    $this->desk->forceFill(['is_disabled' => true])->save();
    ($this->as)($t)->getJson('/api/v1/home')->assertStatus(401)->assertJsonPath('code', 'account_disabled');
    expect($this->desk->tokens()->count())->toBe(0);
});
