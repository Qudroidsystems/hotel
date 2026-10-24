<?php

namespace App\Http\Controllers\Billing;

use App\Enums\ChargeType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Folio;
use App\Models\FolioCharge;
use App\Models\HotelBankAccount;
use App\Models\Payment;
use App\Services\Billing\FolioService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A guest's bill: charges, payments, voids, refunds, invoice and receipts. */
class FolioController extends Controller
{
    public function __construct(private FolioService $folios)
    {
        $this->middleware('permission:View folios')->only(['show', 'print', 'receipt']);
        $this->middleware('permission:Post charges')->only(['storeCharge']);
        $this->middleware('permission:Void charges')->only(['voidCharge']);
        $this->middleware('permission:Void payments')->only(['voidPayment']);
        // storePayment checks Receive payments / Issue refunds itself (depends on the kind).
    }

    public function show(Folio $folio)
    {
        // Bring the room nights up to date before showing the bill.
        if ($folio->reservation) {
            $this->folios->postRoomNights($folio->reservation);
        }

        $folio->load(['guest', 'reservation.rooms.room', 'closedBy']);
        $charges = $folio->charges()->with(['postedBy', 'voidedBy'])->get();
        $payments = $folio->payments()->with(['receivedBy', 'voidedBy', 'bankAccount'])->get();
        $summary = $this->summary($folio, $charges, $payments);
        $pagetitle = 'Bill ' . $folio->number;

        return view('billing.folio', compact('pagetitle', 'folio', 'charges', 'payments', 'summary'));
    }

    public function print(Folio $folio)
    {
        if ($folio->reservation) {
            $this->folios->postRoomNights($folio->reservation);
        }
        $folio->load(['guest', 'reservation.rooms.room']);
        $charges = $folio->charges()->whereNull('voided_at')->get();
        $payments = $folio->payments()->whereNull('voided_at')->with('bankAccount')->get();
        $summary = $this->summary($folio, $charges, $payments);

        $bankAccounts = HotelBankAccount::forInvoices();

        return view('billing.print-invoice', compact('folio', 'charges', 'payments', 'summary', 'bankAccounts'));
    }

    public function receipt(Payment $payment)
    {
        $payment->load(['folio.guest', 'folio.reservation', 'receivedBy', 'bankAccount']);
        $balance = $payment->folio->balance();

        return view('billing.print-receipt', compact('payment', 'balance'));
    }

    public function storeCharge(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'type'         => ['required', Rule::in(array_map(fn ($t) => $t->value, ChargeType::manual()))],
            'description'  => 'required|string|max:255',
            'quantity'     => 'required|numeric|min:0.01|max:9999',
            'unit_price'   => 'required|numeric|min:0.01|max:99999999',
            'direction'    => 'nullable|in:add,reduce',
            'service_date' => 'nullable|date|before_or_equal:today',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        $type = ChargeType::from($data['type']);
        $reduces = $type === ChargeType::Discount || ($type === ChargeType::Adjustment && ($data['direction'] ?? 'add') === 'reduce');

        // Money off the bill needs the discount permission.
        if ($reduces) {
            abort_unless($request->user()->can('Apply reservation discounts'), 403, 'You can’t give discounts or reduce a bill.');
        }
        $price = (float) $data['unit_price'] * ($reduces ? -1 : 1);

        return $this->attempt(fn () => $this->folios->postCharge($folio, $type, $data['description'], (float) $data['quantity'], $price, $data['service_date'] ?? null,
            array_filter(['idempotency_key' => $data['idempotency_key'] ?? null])), $type->label() . ' posted to the bill.');
    }

    public function voidCharge(Request $request, FolioCharge $charge)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->folios->voidCharge($charge, $data['reason']), 'Charge voided.');
    }

    public function storePayment(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'kind'      => ['required', Rule::enum(PaymentKind::class)],
            'method'    => ['required', Rule::enum(PaymentMethod::class)],
            'amount'    => 'required|numeric|min:0.01|max:99999999',
            'reference' => [Rule::requiredIf(fn () => PaymentMethod::tryFrom((string) $request->input('method'))?->needsReference() ?? false), 'nullable', 'string', 'max:100'],
            'notes'     => 'nullable|string|max:255',
            'bank_account_id' => ['nullable', Rule::exists('hotel_bank_accounts', 'id')->where('is_active', true)],
            'idempotency_key' => 'nullable|string|max:64',
        ], ['reference.required' => 'Enter the transfer, card or cheque reference.']);
        $kind = PaymentKind::from($data['kind']);

        abort_unless($request->user()->can($kind === PaymentKind::Refund ? 'Issue refunds' : 'Receive payments'), 403);

        $payment = null;
        $response = $this->attempt(function () use ($folio, $kind, $data, &$payment) {
            $method = PaymentMethod::from($data['method']);
            $payment = $this->folios->receivePayment($folio, $kind, $method, (float) $data['amount'], $data['reference'] ?? null, $data['notes'] ?? null, [
                'bank_account_id' => in_array($method, [PaymentMethod::Transfer, PaymentMethod::Online, PaymentMethod::Cheque], true) ? ($data['bank_account_id'] ?? null) : null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
        }, $kind->label() . ' recorded.');

        return $payment ? $response->with('receipt', $payment->id) : $response;
    }

    public function voidPayment(Request $request, Payment $payment)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->folios->voidPayment($payment, $data['reason']), $payment->kind->label() . ' ' . $payment->receipt_number . ' voided.');
    }

    // ─────────────────────────────────────────────────────────────────

    /** Totals for the bill, including tax that is already inside the prices. */
    private function summary(Folio $folio, $charges, $payments): array
    {
        $valid = $charges->whereNull('voided_at');
        $taxes = [];
        foreach ($valid as $c) {
            foreach ($c->tax_breakdown ?? [] as $t) {
                $key = $t['name'] . ' ' . rtrim(rtrim(number_format((float) $t['rate'], 2), '0'), '.') . '%';
                $taxes[$key] = ($taxes[$key] ?? 0) + (float) $t['amount'];
            }
        }
        $charged = round((float) $valid->sum('total'), 2);
        $paid = round((float) $payments->whereNull('voided_at')->sum(fn ($p) => $p->signed_amount), 2);

        return [
            'subtotal'  => round((float) $valid->sum('amount'), 2),
            'tax_added' => round((float) $valid->sum('tax_amount'), 2),
            'taxes'     => $taxes,
            'inclusive' => $valid->contains(fn ($c) => $c->tax_inclusive),
            'charged'   => $charged,
            'paid'      => $paid,
            'balance'   => round($charged - $paid, 2),
        ];
    }

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
