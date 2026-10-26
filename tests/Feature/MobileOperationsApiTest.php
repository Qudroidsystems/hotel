<?php

use App\Enums\ReservationStatus;
use App\Models\Company;
use App\Models\Events\Event;
use App\Models\NightAudit;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\FolioService;
use Database\Seeders\CommercialDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! class_exists(\Laravel\Sanctum\Sanctum::class)) {
        $this->markTestSkipped('Laravel Sanctum is not installed.');
    }
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class, CommercialDemoSeeder::class]);
    auth()->logout();
    $this->withoutDefer();
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'username' => str($name)->slug('.'), 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->desk = $make('Ada Desk', 'Front Desk');
    $this->cleaner = $make('Bisi Cleaner', 'Housekeeping');
    $this->token = fn (User $u) => $this->postJson('/api/v1/auth/login', ['login' => $u->username, 'password' => 'Secret@123'])->json('data.token');
    $this->as = function (?string $token) {
        app('auth')->forgetGuards();
        $this->flushHeaders();

        return $token ? $this->withToken($token) : $this;
    };
});

test('the front desk closes the day from the app', function () {
    $this->travelTo(today()->setTime(23, 30));
    $t = ($this->token)($this->desk);
    $login = ($this->as)(null)->postJson('/api/v1/auth/login', ['login' => 'ada.desk', 'password' => 'Secret@123'])->json('data.user.modules');
    expect($login)->toContain('night_audit')->toContain('events')->toContain('bill_company');

    $data = ($this->as)($t)->getJson('/api/v1/night-audit')->assertOk()->json('data');
    expect($data['can_close'])->toBeTrue()->and($data['business_date'])->toBe(today()->toDateString())->and($data['checks']['arrivals'])->not->toBeEmpty();

    $keep = $data['checks']['arrivals'][0]['id'];
    $noShows = collect($data['checks']['arrivals'])->pluck('id')->reject(fn ($id) => $id === $keep)->values()->all();
    $res = ($this->as)($t)->postJson('/api/v1/night-audit/close', ['date' => today()->toDateString(), 'no_shows' => $noShows])->assertOk();
    expect(NightAudit::count())->toBe(1)->and(Reservation::find($keep)->status)->not->toBe(ReservationStatus::NoShow);
    ($this->as)($t)->getJson('/api/v1/night-audit/' . $res->json('data.id'))->assertOk()->assertJsonStructure(['data' => ['occupancy', 'revenue', 'report']]);
    ($this->as)($t)->postJson('/api/v1/night-audit/close', ['date' => today()->toDateString()])->assertStatus(422);
});

test('events of the day and their details; staff without access are refused', function () {
    $e = Event::where('status', 'confirmed')->first();
    $this->travelTo($e->starts_at->copy()->setTime(8, 0));
    $t = ($this->token)($this->desk);
    $today = ($this->as)($t)->getJson('/api/v1/events')->assertOk()->json('data');
    expect(collect($today)->pluck('id'))->toContain($e->id);
    $detail = ($this->as)($t)->getJson("/api/v1/events/{$e->id}")->assertOk()->json('data');
    expect($detail['hall'])->not->toBeNull()->and($detail['actions'])->not->toBeEmpty();
    ($this->as)($t)->postJson("/api/v1/events/{$e->id}/status", ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');

    $c = ($this->token)($this->cleaner);
    ($this->as)($c)->getJson('/api/v1/events')->assertForbidden();
});

test('a departing guest’s bill moves to a company from the app', function () {
    $r = Reservation::where('status', ReservationStatus::CheckedIn->value)->whereHas('folio')->first();
    app(FolioService::class)->postRoomNights($r, today()->addDay());
    $folio = $r->folio()->first();
    $owed = $folio->balance();
    $company = Company::where('name', 'MTN Nigeria')->first();
    $t = ($this->token)($this->desk);

    $list = ($this->as)($t)->getJson('/api/v1/companies?q=MTN')->assertOk()->json('data');
    expect($list[0]['name'])->toBe('MTN Nigeria');
    ($this->as)($t)->postJson("/api/v1/folios/{$folio->id}/to-company", ['company_id' => $company->id, 'amount' => $owed])->assertOk()->assertJsonPath('data.balance', 0);
    ($this->as)($t)->postJson("/api/v1/folios/{$folio->id}/to-company", ['company_id' => $company->id, 'amount' => 10])->assertStatus(422);
});
