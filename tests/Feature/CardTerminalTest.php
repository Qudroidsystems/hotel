<?php

use App\Models\Billing\PaymentTerminal;
use App\Models\Billing\TerminalPayment;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Pos\PosOutlet;
use App\Models\Pos\PosOrder;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\TerminalService;
use App\Services\Billing\FolioService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PosDemoSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->gm = $make('Grace Manager', 'General Manager');
    $this->desk = $make('Dayo Desk', 'Front Desk');
    $this->cleaner = $make('Bisi Cleaner', 'Housekeeping');
    $this->folio = Folio::whereHas('reservation', fn ($q) => $q->where('status', 'checked_in'))->where('status', 'open')->firstOrFail();
    app(FolioService::class)->postCharge($this->folio, \App\Enums\ChargeType::Food, 'Dinner', 1, 25000);
});

afterEach(fn () => Carbon::setTestNow());

test('a manager adds a terminal; front desk cannot; housekeeping cannot see the page', function () {
    $this->actingAs($this->gm)->post(route('terminals.store'), ['name' => 'Front desk 1', 'provider' => 'demo', 'identifier' => 'D1'])->assertSessionHas('success');
    $t = PaymentTerminal::sole();
    expect($t->is_active)->toBeTrue()->and($t->label)->toBe('Front desk 1 · Demo');
    $this->actingAs($this->gm)->post(route('terminals.store'), ['name' => 'Again', 'provider' => 'demo', 'identifier' => 'D1'])->assertSessionHasErrors('identifier');

    $this->actingAs($this->gm)->get(route('terminals.index'))->assertOk()->assertSee('Front desk 1')->assertSee('Add a terminal');
    $this->actingAs($this->gm)->put(route('terminals.update', $t), ['name' => 'Front desk 1', 'provider' => 'demo', 'identifier' => 'D1'])->assertRedirect();
    expect($t->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->desk)->post(route('terminals.store'), ['name' => 'X', 'provider' => 'demo', 'identifier' => 'X'])->assertForbidden();
    $this->actingAs($this->cleaner)->get(route('terminals.index'))->assertForbidden();
});

test('the demo terminal pays a bill by itself once, with the approval code', function () {
    $t = PaymentTerminal::create(['name' => 'Desk', 'provider' => 'demo', 'identifier' => 'D1', 'is_active' => true]);
    $before = $this->folio->balance();

    $this->actingAs($this->desk)->get(route('folios.show', $this->folio))->assertOk()->assertSee('Card terminal');
    $res = $this->actingAs($this->desk)->postJson(route('terminal-payments.send'), ['terminal_id' => $t->id, 'amount' => 10000, 'folio_id' => $this->folio->id])
        ->assertCreated()->assertJsonPath('payment.status', 'waiting');
    $id = $res->json('payment.id');

    // The terminal is busy until this one ends.
    $this->actingAs($this->desk)->postJson(route('terminal-payments.send'), ['terminal_id' => $t->id, 'amount' => 500, 'folio_id' => $this->folio->id])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'still waiting'));

    $this->actingAs($this->desk)->getJson(route('terminal-payments.show', $id))->assertOk()->assertJsonPath('payment.open', true);

    Carbon::setTestNow(now()->addSeconds(10));
    $this->actingAs($this->desk)->getJson(route('terminal-payments.show', $id))->assertOk()
        ->assertJsonPath('payment.paid', true)->assertJsonPath('payment.card', 'VISA •••• 4081');
    $this->actingAs($this->desk)->getJson(route('terminal-payments.show', $id))->assertOk();
    app(TerminalService::class)->checkOpen();

    $pay = Payment::where('idempotency_key', 'terminal:' . TerminalPayment::find($id)->reference)->sole();
    expect($pay->method->value)->toBe('card')->and((float) $pay->amount)->toBe(10000.0)->and($pay->received_by)->toBe($this->desk->id)
        ->and($pay->reference)->toStartWith('DEMO')
        ->and(round($this->folio->fresh()->balance(), 2))->toBe(round($before - 10000, 2))
        ->and(TerminalPayment::find($id)->payment_id)->toBe($pay->id);

    $this->actingAs($this->gm)->get(route('terminals.index'))->assertOk()->assertSee('Bill ' . $this->folio->number)->assertSee($pay->receipt_number)->assertSee('Paid');
    $this->actingAs($this->gm)->get(route('settings.hotel.index', ['tab' => 'terminals']))->assertOk()->assertSee('Moniepoint');
});

test('a declined card is not recorded; cancel and expiry close the request', function () {
    $t = PaymentTerminal::create(['name' => 'Desk', 'provider' => 'demo', 'identifier' => 'D1', 'is_active' => true]);
    $svc = app(TerminalService::class);

    $p = $svc->request($t, 500.13, ['folio' => $this->folio], $this->desk);
    Carbon::setTestNow(now()->addSeconds(10));
    expect($svc->refresh($p)->status)->toBe('failed')->and(Payment::where('idempotency_key', 'like', 'terminal:%')->count())->toBe(0);

    $p = $svc->request($t, 700, ['folio' => $this->folio], $this->desk);
    expect($svc->cancel($p->load('terminal'), $this->desk)->status)->toBe('cancelled');

    Carbon::setTestNow(now()->addSeconds(30));
    $p = $svc->request($t, 700.13, ['folio' => $this->folio], $this->desk);
    Carbon::setTestNow(now()->addMinutes(30));
    expect($svc->refresh($p->load('terminal'))->status)->toBe('failed');   // the last look still sees the decline

    $this->actingAs($this->cleaner)->postJson(route('terminal-payments.send'), ['terminal_id' => $t->id, 'amount' => 100, 'folio_id' => $this->folio->id])->assertForbidden();
});

test('paystack: payment request pushed to the terminal, the webhook only triggers a check', function () {
    config(['services.paystack.secret_key' => 'sk_test_x', 'hotel.terminals.paystack_customer_email' => 'walkin@hotel.test']);
    $t = PaymentTerminal::create(['name' => 'Desk', 'provider' => 'paystack', 'identifier' => 'TRM_abc', 'is_active' => true]);
    $paid = false;
    Http::fake(function ($req) use (&$paid) {
        $u = $req->url();

        return match (true) {
            str_ends_with($u, '/customer')               => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_1']]),
            str_ends_with($u, '/paymentrequest')         => Http::response(['status' => true, 'data' => ['id' => 99, 'request_code' => 'PRQ_1', 'offline_reference' => '7891']]),
            str_contains($u, '/terminal/TRM_abc/event')  => Http::response(['status' => true, 'data' => ['id' => 'evt']]),
            str_contains($u, '/paymentrequest/verify/PRQ_1') => Http::response(['status' => true, 'data' => $paid
                ? ['status' => 'success', 'paid' => true, 'amount' => 2000000, 'payments' => [['reference' => 'T1234', 'authorization' => ['last4' => '4242', 'card_type' => 'visa']]]]
                : ['status' => 'pending', 'paid' => false]]),
            default => Http::response(['status' => false, 'message' => 'nope'], 404),
        };
    });

    $p = app(TerminalService::class)->request($t, 20000, ['folio' => $this->folio], $this->desk);
    expect($p->provider_ref)->toBe('PRQ_1')->and($p->status)->toBe('waiting');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/paymentrequest') && $r['amount'] === 2000000 && $r['customer'] === 'CUS_1');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/event') && $r['type'] === 'invoice' && $r['data']['reference'] === '7891');

    // A forged webhook does nothing; a signed one makes us ask Paystack.
    $body = json_encode(['event' => 'paymentrequest.success', 'data' => ['id' => 99, 'request_code' => 'PRQ_1']]);
    $this->call('POST', route('book.paystack.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'bad'], $body);
    $paid = true;
    $this->call('POST', route('book.paystack.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_x')], $body)->assertOk();

    $p->refresh();
    expect($p->status)->toBe('paid')->and($p->approval)->toBe('T1234')->and($p->card)->toBe('VISA •••• 4242')
        ->and(Payment::find($p->payment_id)->reference)->toBe('T1234');
});

test('moniepoint: pushed by serial number in kobo, signed webhook, approved by response code', function () {
    config(['services.moniepoint' => ['base_url' => 'https://mp.test', 'client_id' => 'id', 'client_secret' => 'sec', 'webhook_secret' => 'whsec', 'amount_in_kobo' => true]]);
    $t = PaymentTerminal::create(['name' => 'Bar', 'provider' => 'moniepoint', 'identifier' => 'P260300012345', 'is_active' => true]);
    $state = ['processingStatus' => 'PENDING'];
    Http::fake([
        'mp.test/v1/auth' => Http::response(['accessToken' => 'tok']),
        'mp.test/v1/transactions' => Http::response([], 202),
        'mp.test/v1/transactions/merchants/*' => function () use (&$state) { return Http::response($state); },
    ]);
    $p = app(TerminalService::class)->request($t, 15000, ['folio' => $this->folio], $this->desk);
    Http::assertSent(fn ($r) => $r->url() === 'https://mp.test/v1/transactions' && $r['amount'] === 1500000 && $r['terminalSerial'] === 'P260300012345' && $r->hasHeader('Authorization', 'Bearer tok'));

    $state = ['processingStatus' => 'PROCESSED', 'responseCode' => '00', 'transactionReference' => 'MP-777', 'actualAmount' => 1500000, 'actualPaymentMethod' => 'CARD_PURCHASE'];
    $body = json_encode(['eventType' => 'TRANSACTION_COMPLETED', 'data' => ['merchantReference' => $p->reference]]);
    $sig = base64_encode(hash_hmac('sha256', "wh1__1700000000__{$body}", 'whsec', true));
    $this->call('POST', route('terminals.webhook.moniepoint'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_MONIEPOINT_WEBHOOK_ID' => 'wh1', 'HTTP_MONIEPOINT_WEBHOOK_TIMESTAMP' => '1700000000', 'HTTP_MONIEPOINT_WEBHOOK_SIGNATURE' => 'wrong'], $body)->assertStatus(401);
    expect($p->fresh()->status)->toBe('waiting');
    $this->call('POST', route('terminals.webhook.moniepoint'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_MONIEPOINT_WEBHOOK_ID' => 'wh1', 'HTTP_MONIEPOINT_WEBHOOK_TIMESTAMP' => '1700000000', 'HTTP_MONIEPOINT_WEBHOOK_SIGNATURE' => $sig], $body)->assertOk();

    $p->refresh();
    expect($p->status)->toBe('paid')->and((float) $p->paid_amount)->toBe(15000.0)->and($p->approval)->toBe('MP-777')->and($p->payment_id)->not->toBeNull();
});

test('opay: the request is RSA-encrypted and signed, the answer decrypted', function () {
    $opay = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $hotel = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($opay, $opayPriv); openssl_pkey_export($hotel, $hotelPriv);
    $opayPub = openssl_pkey_get_details($opay)['key']; $hotelPub = openssl_pkey_get_details($hotel)['key'];
    // Bare base64 (as OPay's dashboard shows it) must work too.
    $bare = preg_replace('/-----[^-]+-----|\s+/', '', $opayPub);
    config(['services.opay_pos' => ['base_url' => 'https://opay.test/api', 'head_merchant_id' => 'H1', 'merchant_id' => 'M1', 'client_auth_key' => 'auth', 'sub_scene' => '', 'opay_public_key' => $bare, 'merchant_private_key' => $hotelPriv]]);

    $seal = function (array $data) use ($hotelPub) {   // OPay encrypts its answers with the hotel's public key
        $out = '';
        foreach (str_split(json_encode($data), 117) as $c) { openssl_public_encrypt($c, $e, $hotelPub, OPENSSL_PKCS1_PADDING); $out .= $e; }

        return base64_encode($out);
    };
    $open = function (string $b64) use ($opayPriv) {
        $out = '';
        foreach (str_split(base64_decode($b64), 128) as $c) { openssl_private_decrypt($c, $d, $opayPriv, OPENSSL_PKCS1_PADDING); $out .= $d; }

        return json_decode($out, true);
    };
    $status = 'INITIAL'; $sent = null;
    Http::fake(function ($req) use (&$status, &$sent, $seal, $open, $hotelPub) {
        $ok = openssl_verify($req['paramContent'] . $req->header('timestamp')[0], base64_decode($req['sign']), $hotelPub, OPENSSL_ALGO_SHA256) === 1;
        if (! $ok || $req->header('clientAuthKey')[0] !== 'auth') {
            return Http::response(['code' => '02001', 'message' => 'bad sign']);
        }
        $params = $open($req['paramContent']);
        if (str_ends_with($req->url(), '/createOrder')) {
            $sent = $params;

            return Http::response(['code' => '00000', 'data' => $seal(['orderNo' => 'OP123', 'outOrderNo' => $params['outOrderNo']])]);
        }

        return Http::response(['code' => '00000', 'data' => $seal(['orderNo' => 'OP123', 'status' => $status, 'amount' => '9000.00', 'payNo' => 'PAY9'])]);
    });

    $t = PaymentTerminal::create(['name' => 'Pool', 'provider' => 'opay', 'identifier' => 'SN0042', 'is_active' => true]);
    $svc = app(TerminalService::class);
    $p = $svc->request($t, 9000, ['folio' => $this->folio], $this->desk);
    expect($p->provider_ref)->toBe('OP123')->and($sent['sn'])->toBe('SN0042')->and($sent['amount'])->toBe('9000.00')->and(array_keys($sent))->toBe(collect(array_keys($sent))->sort()->values()->all());

    expect($svc->refresh($p)->status)->toBe('waiting');
    $status = 'SUCCESS';
    $this->postJson(route('terminals.webhook.opay'), ['outOrderNo' => $p->reference], ['X-Opay-Tranid' => 'OP123'])->assertOk()->assertJsonPath('code', '00000');
    expect($p->fresh()->status)->toBe('paid')->and($p->fresh()->approval)->toBe('PAY9');
});

test('the bar till: terminals in the boot data, a paid card sale is linked once synced', function () {
    $this->seed(PosDemoSeeder::class);
    $outlet = PosOutlet::orderBy('id')->first();
    $cashier = User::role('Cashier')->first() ?? tap(User::create(['name' => 'Cash Ier', 'email' => 'cashier@test.local', 'password' => bcrypt('x')]))->assignRole('Cashier');
    $t = PaymentTerminal::create(['name' => 'Bar', 'provider' => 'demo', 'identifier' => 'BAR', 'pos_outlet_id' => $outlet->id, 'is_active' => true]);

    $boot = app(\App\Services\Pos\PosService::class)->terminalData($cashier);
    expect($boot['card_terminals'][0]['id'])->toBe($t->id);

    $id = $this->actingAs($cashier)->postJson(route('terminal-payments.send'), ['terminal_id' => $t->id, 'amount' => 3000, 'outlet_id' => $outlet->id])->assertCreated()->json('payment.id');
    Carbon::setTestNow(now()->addSeconds(10));
    $this->actingAs($cashier)->getJson(route('terminal-payments.show', $id))->assertJsonPath('payment.paid', true);
    expect(TerminalPayment::find($id)->payment_id)->toBeNull();   // a bar sale, not a bill

    $item = \App\Models\Pos\PosItem::whereHas('outlets', fn ($q) => $q->whereKey($outlet->id))->first();
    $uuid = (string) Str::uuid();
    $order = ['uuid' => $uuid, 'number' => $outlet->code . '-T1-0001', 'outlet_id' => $outlet->id, 'ordered_at' => now()->toIso8601String(), 'method' => 'card',
        'reference' => 'DEMO1', 'terminal_payment_id' => $id, 'total' => (float) $item->price,
        'items' => [['item_id' => $item->id, 'name' => $item->name, 'charge_type' => $item->charge_type->value, 'quantity' => 1, 'unit_price' => (float) $item->price]]];
    $r = $this->actingAs($cashier)->postJson(route('pos.sync'), ['orders' => [$order]])->assertOk();
    expect($r->json('results.0.result'))->toBe('ok')->and(TerminalPayment::find($id)->pos_order_uuid)->toBe($uuid);
});

test('the staff app can send to a terminal through the API', function () {
    $t = PaymentTerminal::create(['name' => 'Desk', 'provider' => 'demo', 'identifier' => 'D1', 'is_active' => true]);
    $token = $this->desk->createToken('app')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/terminal-payments/terminals')->assertOk()->assertJsonPath('terminals.0.name', 'Desk');
    $id = $this->withToken($token)->postJson('/api/v1/terminal-payments', ['terminal_id' => $t->id, 'amount' => 1000, 'folio_id' => $this->folio->id])->assertCreated()->json('payment.id');
    $this->withToken($token)->postJson("/api/v1/terminal-payments/{$id}/cancel")->assertOk()->assertJsonPath('payment.status', 'cancelled');
});
