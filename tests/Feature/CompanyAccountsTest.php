<?php

use App\Enums\ReservationStatus;
use App\Models\Company;
use App\Models\CompanyLedgerEntry;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Companies\CompanyLedger;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    $this->withoutDefer();
    $this->ledger = app(CompanyLedger::class);
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->first();
    $this->folio = app(FolioService::class)->forReservation($this->r);
    app(FolioService::class)->postRoomNights($this->r, today()->addDay());
});

test('a manager opens an account; the guest’s bill moves to it and the guest can leave settled', function () {
    $this->post(route('companies.store'), ['name' => 'Dangote Cement', 'contact_name' => 'Mrs Bello', 'credit_limit' => 0, 'payment_terms_days' => 30])->assertRedirect();
    $company = Company::where('name', 'Dangote Cement')->first();
    expect($company->account_number)->toBe('CA-0001');

    $owed = $this->folio->fresh()->balance();
    expect($owed)->toBeGreaterThan(0);
    $this->post(route('folios.to-company', $this->folio), ['company_id' => $company->id, 'amount' => $owed, 'note' => 'LPO 4471'])->assertSessionHas('success');

    expect($this->folio->fresh()->balance())->toEqualWithDelta(0, 0.01)
        ->and($company->balance())->toEqualWithDelta($owed, 0.01);
    $inv = CompanyLedgerEntry::first();
    expect($inv->number)->toStartWith('CI-')->and($inv->due_date->toDateString())->toBe(today()->addDays(30)->toDateString());

    $this->get(route('companies.show', $company))->assertOk()->assertSee($inv->number)->assertSee('Mrs Bello');
    $this->get(route('companies.invoice', [$company, $inv]))->assertOk()->assertSee($this->r->reference)->assertSee('Billed to Dangote Cement');
    $this->get(route('companies.statement', $company))->assertOk()->assertSee('Amount due');
    $this->get(route('companies.index', ['show' => 'owing']))->assertOk()->assertSee('Dangote Cement');
});

test('credit limits are enforced, payments clear the oldest invoices, and late money shows in the ageing', function () {
    $company = $this->ledger->create(['name' => 'Shell', 'credit_limit' => 50000, 'payment_terms_days' => 14]);
    $this->ledger->invoice($company, 45000, 'Earlier stay');
    expect(fn () => $this->ledger->transferFolio($this->folio, $company, 10000))->toThrow(\App\Exceptions\BookingException::class, 'credit limit');
    $company->entries()->delete();
    $company->update(['credit_limit' => 0]);

    $old = $this->ledger->invoice($company, 30000, 'Conference coffee break');
    $old->forceFill(['entry_date' => today()->subDays(50), 'due_date' => today()->subDays(36)])->save();
    $this->ledger->invoice($company, 15000, 'Room hire');
    $age = $this->ledger->ageing($company);
    expect($age['buckets']['d60'])->toEqual(30000.0)->and($age['buckets']['current'])->toEqual(15000.0)->and($age['overdue'])->toEqual(30000.0);

    $this->post(route('companies.payment', $company), ['amount' => 35000, 'method' => 'transfer', 'reference' => 'TRF-889'])->assertSessionHas('success');
    $age = $this->ledger->ageing($company->fresh());
    expect($age['overdue'])->toEqual(0.0)->and($age['buckets']['current'])->toEqual(10000.0)->and($company->balance())->toEqual(10000.0);
});

test('voiding an invoice puts the amount back on the guest’s bill, and voiding the bill’s payment voids the invoice', function () {
    $company = $this->ledger->create(['name' => 'MTN', 'payment_terms_days' => 30]);
    $owed = $this->folio->fresh()->balance();
    $inv = $this->ledger->transferFolio($this->folio, $company, $owed);

    $this->post(route('companies.void', [$company, $inv]), ['reason' => 'Wrong company'])->assertSessionHas('success');
    expect($inv->fresh()->isVoided())->toBeTrue()->and($this->folio->fresh()->balance())->toEqualWithDelta($owed, 0.01)->and($company->balance())->toEqual(0.0);

    $inv2 = $this->ledger->transferFolio($this->folio->fresh(), $company, 1000);
    app(FolioService::class)->voidPayment($inv2->payment, 'Guest paid cash instead');
    expect($inv2->fresh()->isVoided())->toBeTrue()->and($company->balance())->toEqual(0.0);
});

test('company bookings: negotiated rate, guest linked, and permissions', function () {
    $company = $this->ledger->create(['name' => 'Chevron', 'payment_terms_days' => 30]);
    $plan = \App\Models\Rates\RatePlan::create(['name' => 'Chevron rate', 'code' => 'CHV', 'discount_percent' => 20, 'company_id' => $company->id]);
    $in = today()->addDays(30);
    $type = \App\Models\RoomType::where('code', 'EXE')->first();

    $this->post(route('reservations.store'), [
        'check_in_date' => $in->toDateString(), 'check_out_date' => $in->copy()->addDay()->toDateString(), 'source' => 'phone', 'status' => 'confirmed',
        'rate_plan_id' => $plan->id, 'rooms' => [['room_type_id' => $type->id, 'adults' => 1]],
        'guest' => ['first_name' => 'Uche', 'last_name' => 'Eze', 'phone' => '08035550000'],
    ])->assertSessionHas('error');   // the rate is only for Chevron's bookings

    $this->post(route('reservations.store'), [
        'check_in_date' => $in->toDateString(), 'check_out_date' => $in->copy()->addDay()->toDateString(), 'source' => 'phone', 'status' => 'confirmed',
        'rate_plan_id' => $plan->id, 'company_id' => $company->id, 'rooms' => [['room_type_id' => $type->id, 'adults' => 1]],
        'guest' => ['first_name' => 'Uche', 'last_name' => 'Eze', 'phone' => '08035550000'],
    ])->assertRedirect();
    $r = Reservation::latest('id')->first();
    expect($r->company_id)->toBe($company->id)->and($r->guest->company_id)->toBe($company->id)->and($r->rate_plan_name)->toBe('Chevron rate');
    $this->get(route('reservations.show', $r))->assertOk()->assertSee('Bill to Chevron');

    $fd = User::create(['name' => 'FD', 'email' => 'fd@test.local', 'password' => bcrypt('Secret@123')]);
    $fd->assignRole('Front Desk');
    $this->actingAs($fd)->get(route('companies.show', $company))->assertOk();
    $this->actingAs($fd)->get(route('companies.create'))->assertForbidden();
    $this->actingAs($fd)->post(route('companies.payment', $company), ['amount' => 10, 'method' => 'cash'])->assertForbidden();
    $hk = User::create(['name' => 'HK', 'email' => 'hk@test.local', 'password' => bcrypt('Secret@123')]);
    $hk->assignRole('Housekeeping');
    $this->actingAs($hk)->get(route('companies.index'))->assertForbidden();
});
