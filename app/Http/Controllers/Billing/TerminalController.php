<?php

namespace App\Http\Controllers\Billing;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Billing\PaymentTerminal;
use App\Models\Billing\TerminalPayment;
use App\Models\Folio;
use App\Models\Pos\PosOutlet;
use App\Services\Billing\TerminalService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Billing → Card Terminals: the terminals linked to the system and every
 * amount sent to them. Also the JSON the bill page and the bar till use to
 * send an amount and watch for the card.
 */
class TerminalController extends Controller
{
    public function __construct(private TerminalService $terminals)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['Manage card terminals', 'View finance reports']), 403);
        $terminals = PaymentTerminal::with('outlet:id,name')->withCount(['payments as paid_today' => fn ($q) => $q->where('status', 'paid')->whereDate('paid_at', today())])
            ->orderByDesc('is_active')->orderBy('name')->get();
        $status = array_key_exists((string) $request->query('status'), TerminalPayment::STATUSES) ? $request->query('status') : null;
        $payments = TerminalPayment::with(['terminal:id,name,provider', 'folio:id,number', 'payment:id,receipt_number', 'requester:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->integer('terminal'), fn ($q, $id) => $q->where('payment_terminal_id', $id))
            ->latest()->paginate(40)->withQueryString();
        $today = TerminalPayment::whereDate('created_at', today())->get(['status', 'paid_amount', 'amount', 'pos_outlet_id', 'pos_order_uuid', 'folio_id']);
        $stats = [
            'paid'     => $today->where('status', 'paid')->count(),
            'amount'   => (float) $today->where('status', 'paid')->sum('paid_amount'),
            'failed'   => $today->whereIn('status', ['failed', 'expired'])->count(),
            'unlinked' => TerminalPayment::where('status', 'paid')->whereNull('folio_id')->whereNull('pos_order_uuid')->where('source', 'pos')->where('paid_at', '>=', now()->subDays(7))->count(),
        ];
        $outlets = PosOutlet::active()->ordered()->get(['id', 'name']);
        $edit = $request->integer('edit') ? $terminals->firstWhere('id', $request->integer('edit')) : null;
        $pagetitle = 'Card Terminals';

        return view('billing.terminals', compact('pagetitle', 'terminals', 'payments', 'stats', 'outlets', 'edit', 'status'));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('Manage card terminals'), 403);
        $t = PaymentTerminal::create($this->data($request) + ['is_active' => true]);
        Audit::record('billing.terminal_added', "Added card terminal {$t->name} ({$t->provider_label} {$t->identifier})", $t);

        return redirect()->route('terminals.index')->with('success', "{$t->name} added. Use “Test” to check it answers.");
    }

    public function update(Request $request, PaymentTerminal $terminal)
    {
        abort_unless($request->user()->can('Manage card terminals'), 403);
        $terminal->update($this->data($request, $terminal) + ['is_active' => $request->boolean('is_active')]);
        Audit::record('billing.terminal_changed', "Changed card terminal {$terminal->name}", $terminal);

        return redirect()->route('terminals.index')->with('success', 'Saved.');
    }

    public function test(Request $request, PaymentTerminal $terminal)
    {
        abort_unless($request->user()->can('Manage card terminals'), 403);
        $r = $this->terminals->test($terminal);

        return back()->with($r['ok'] ? 'success' : 'error', "{$terminal->name}: {$r['message']}");
    }

    // ── JSON: send an amount and watch it ─────────────────────────────

    /** GET /terminal-payments/terminals?outlet= — the terminals to choose from. */
    public function choices(Request $request)
    {
        abort_unless($request->user()->canAny(['Receive payments', 'Use POS']), 403);

        return response()->json(['terminals' => $this->terminals->available($request->integer('outlet') ?: null)
            ->map(fn (PaymentTerminal $t) => ['id' => $t->id, 'name' => $t->name, 'provider' => $t->provider_label, 'outlet_id' => $t->pos_outlet_id])->values()]);
    }

    /** POST /terminal-payments {terminal_id, amount, folio_id?, outlet_id?} */
    public function send(Request $request)
    {
        $data = $request->validate([
            'terminal_id' => ['required', Rule::exists('payment_terminals', 'id')],
            'amount'      => 'required|numeric|min:1|max:99999999',
            'folio_id'    => ['nullable', Rule::exists('folios', 'id')],
            'outlet_id'   => ['nullable', Rule::exists('pos_outlets', 'id')],
        ]);
        $user = $request->user();
        abort_unless(! empty($data['outlet_id']) ? $user->can('Use POS') : $user->can('Receive payments'), 403);
        try {
            $p = $this->terminals->request(PaymentTerminal::findOrFail($data['terminal_id']), (float) $data['amount'],
                array_filter(['folio' => ! empty($data['folio_id']) ? Folio::find($data['folio_id']) : null, 'outlet_id' => $data['outlet_id'] ?? null]),
                $user, ! empty($data['outlet_id']) ? 'pos' : 'web');
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['payment' => $p->fresh(['terminal', 'payment'])->toClient()], 201);
    }

    /** GET /terminal-payments/{payment} — asks the provider, then answers. */
    public function show(Request $request, TerminalPayment $payment)
    {
        abort_unless($request->user()->canAny(['Receive payments', 'Use POS', 'Manage card terminals']), 403);

        return response()->json(['payment' => $this->terminals->refresh($payment->load('terminal'))->fresh(['terminal', 'payment'])->toClient()]);
    }

    public function cancel(Request $request, TerminalPayment $payment)
    {
        abort_unless($request->user()->canAny(['Receive payments', 'Use POS', 'Manage card terminals']), 403);
        $p = $this->terminals->cancel($payment->load('terminal'), $request->user());

        return $request->expectsJson()
            ? response()->json(['payment' => $p->fresh(['terminal', 'payment'])->toClient()])
            : back()->with($p->status === 'paid' ? 'success' : 'info', $p->status === 'paid' ? 'It was already paid.' : 'Cancelled.');
    }

    private function data(Request $request, ?PaymentTerminal $t = null): array
    {
        return $request->validate([
            'name'          => 'required|string|max:80',
            'provider'      => ['required', Rule::in(array_keys(PaymentTerminal::PROVIDERS))],
            'identifier'    => ['required', 'string', 'max:80', Rule::unique('payment_terminals')->where('provider', $request->input('provider'))->ignore($t?->id)],
            'pos_outlet_id' => ['nullable', Rule::exists('pos_outlets', 'id')],
            'location'      => 'nullable|string|max:120',
        ]);
    }
}
