<?php

namespace App\Http\Controllers\Events;

use App\Enums\PaymentMethod;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Events\Event;
use App\Models\Events\EventItem;
use App\Models\Events\EventPackage;
use App\Models\Events\EventPayment;
use App\Models\Events\Hall;
use App\Models\HotelBankAccount;
use App\Services\Events\EventService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Events & halls: enquiries, bookings, the hall calendar, extras, payments and the event sheet. */
class EventController extends Controller
{
    public function __construct(private EventService $events)
    {
        $this->middleware('permission:View events|Manage events', ['only' => ['index', 'show', 'sheet']]);
        $this->middleware('permission:Manage events', ['only' => ['create', 'store', 'edit', 'update', 'status', 'addItem', 'removeItem']]);
        $this->middleware('permission:Receive payments', ['only' => ['payment', 'voidPayment']]);
        $this->middleware('permission:Bill to company', ['only' => ['toCompany']]);
    }

    public function index(Request $request)
    {
        $view = in_array($request->query('view'), ['list', 'calendar'], true) ? $request->query('view') : 'list';
        $halls = Hall::active()->ordered()->get();
        $pagetitle = 'Events & Halls';
        $stats = [
            'upcoming' => Event::holding()->where('starts_at', '>=', today())->count(),
            'enquiries' => Event::where('status', 'enquiry')->where('starts_at', '>=', today())->count(),
            'month' => round((float) Event::whereIn('status', ['confirmed', 'completed'])->whereBetween('starts_at', [today()->startOfMonth(), today()->endOfMonth()])->sum('total'), 2),
            'deposits' => Event::holding()->where('deposit_due', '>', 0)->where('starts_at', '>=', today())->with('payments')->get()->filter(fn ($e) => $e->paid() + 0.001 < (float) $e->deposit_due)->count(),
        ];

        if ($view === 'calendar') {
            $month = $request->filled('month') ? Carbon::createFromFormat('Y-m', $request->query('month'))->startOfMonth()->startOfDay() : today()->startOfMonth();
            $days = collect(range(0, $month->daysInMonth - 1))->map(fn ($i) => $month->copy()->addDays($i));
            $events = Event::with('hall')->where('status', '!=', 'cancelled')->overlapping($month, $month->copy()->endOfMonth()->addDay())->orderBy('starts_at')->get();

            return view('events.index', compact('pagetitle', 'view', 'halls', 'stats', 'month', 'days', 'events'));
        }

        $show = in_array($request->query('show'), ['upcoming', 'enquiries', 'past', 'cancelled', 'all'], true) ? $request->query('show') : 'upcoming';
        $events = Event::with(['hall', 'company', 'payments'])
            ->when($request->query('hall'), fn ($q, $h) => $q->where('hall_id', $h))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('title', 'like', "%{$t}%")->orWhere('number', 'like', "%{$t}%")->orWhere('organiser_name', 'like', "%{$t}%")))
            ->when($show === 'upcoming', fn ($q) => $q->whereIn('status', ['enquiry', 'tentative', 'confirmed'])->where('ends_at', '>=', now()))
            ->when($show === 'enquiries', fn ($q) => $q->where('status', 'enquiry'))
            ->when($show === 'past', fn ($q) => $q->where('ends_at', '<', now())->where('status', '!=', 'cancelled'))
            ->when($show === 'cancelled', fn ($q) => $q->where('status', 'cancelled'))
            ->orderBy('starts_at', in_array($show, ['past', 'cancelled', 'all'], true) ? 'desc' : 'asc')
            ->paginate(25)->withQueryString();

        return view('events.index', compact('pagetitle', 'view', 'halls', 'stats', 'events', 'show'));
    }

    public function create(Request $request)
    {
        $event = new Event([
            'hall_id' => $request->query('hall'),
            'starts_at' => Carbon::parse($request->query('date', today()->addWeek()->toDateString()))->setTime(10, 0),
            'ends_at' => Carbon::parse($request->query('date', today()->addWeek()->toDateString()))->setTime(16, 0),
        ]);

        return $this->form($event);
    }

    public function store(Request $request)
    {
        try {
            $event = $this->events->save($this->validated($request));
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('events.show', $event)->with('success', "{$event->number} saved as {$event->status_label}.");
    }

    public function edit(Event $event)
    {
        return $this->form($event);
    }

    public function update(Request $request, Event $event)
    {
        try {
            $this->events->save($this->validated($request), $event);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('events.show', $event)->with('success', 'Saved.');
    }

    public function show(Event $event)
    {
        $event->load(['hall', 'package', 'company', 'items', 'payments.receiver', 'creator']);
        $invoices = $event->companyInvoices()->with('company')->get();

        return view('events.show', [
            'pagetitle' => $event->title, 'event' => $event, 'invoices' => $invoices,
            'companies' => Company::active()->orderBy('name')->get(), 'bankAccounts' => HotelBankAccount::active()->ordered()->get(),
        ]);
    }

    public function status(Request $request, Event $event)
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Event::STATUSES))], 'reason' => 'nullable|string|max:255']);
        try {
            $this->events->setStatus($event, $data['status'], $data['reason'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$event->number} is now {$event->fresh()->status_label}.");
    }

    public function addItem(Request $request, Event $event)
    {
        $data = $request->validate([
            'kind'        => ['required', Rule::in(array_keys(EventItem::KINDS))],
            'description' => 'required|string|max:150',
            'quantity'    => 'required|numeric|min:0.01|max:100000',
            'unit_price'  => 'required|numeric|min:-100000000|max:100000000',
        ]);
        try {
            $this->events->addItem($event, $data);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Added.');
    }

    public function removeItem(Event $event, EventItem $item)
    {
        abort_unless($item->event_id === $event->id, 404);
        try {
            $this->events->removeItem($event, $item);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Removed.');
    }

    public function payment(Request $request, Event $event)
    {
        $data = $request->validate([
            'kind'            => 'required|in:payment,refund',
            'method'          => ['required', Rule::in(array_map(fn ($m) => $m->value, PaymentMethod::takeable()))],
            'amount'          => 'required|numeric|min:0.01|max:1000000000',
            'reference'       => 'nullable|string|max:100',
            'bank_account_id' => 'nullable|integer|exists:hotel_bank_accounts,id',
        ]);
        try {
            $p = $this->events->receivePayment($event, $data['kind'], PaymentMethod::from($data['method']), (float) $data['amount'], $data['reference'] ?? null, $data['bank_account_id'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', ($p->kind === 'refund' ? 'Refund ' : 'Payment ') . "{$p->number} recorded.");
    }

    public function voidPayment(Request $request, Event $event, EventPayment $payment)
    {
        abort_unless($payment->event_id === $event->id, 404);
        $data = $request->validate(['reason' => 'required|string|max:200']);
        try {
            $this->events->voidPayment($payment, $data['reason']);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$payment->number} voided.");
    }

    public function toCompany(Request $request, Event $event)
    {
        $data = $request->validate(['company_id' => 'required|integer|exists:companies,id', 'amount' => 'required|numeric|min:0.01|max:1000000000']);
        try {
            $entry = $this->events->billToCompany($event, Company::findOrFail($data['company_id']), (float) $data['amount']);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Billed to {$entry->company->name} (invoice {$entry->number}).");
    }

    /** The event sheet for the kitchen, setup crew and front desk (banquet event order). */
    public function sheet(Event $event)
    {
        $event->load(['hall', 'package', 'company', 'items']);

        return view('events.sheet', compact('event'));
    }

    private function form(Event $event)
    {
        return view('events.form', [
            'pagetitle' => $event->exists ? 'Edit ' . $event->number : 'New Event',
            'event' => $event,
            'halls' => Hall::active()->ordered()->get(),
            'packages' => EventPackage::where('is_active', true)->orderBy('name')->get(),
            'companies' => Company::active()->orderBy('name')->get(),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title'           => 'required|string|max:150',
            'event_type'      => ['required', Rule::in(array_keys(Event::TYPES))],
            'status'          => ['nullable', Rule::in(['enquiry', 'tentative', 'confirmed'])],
            'hall_id'         => 'required|integer|exists:halls,id',
            'date'            => 'required|date',
            'start_time'      => 'required|date_format:H:i',
            'end_date'        => 'nullable|date|after_or_equal:date',
            'end_time'        => 'required|date_format:H:i',
            'setup'           => ['nullable', Rule::in(array_keys(Hall::SETUPS))],
            'guests_expected' => 'required|integer|min:1|max:20000',
            'company_id'      => 'nullable|integer|exists:companies,id',
            'organiser_name'  => 'required|string|max:120',
            'organiser_phone' => 'nullable|string|max:40',
            'organiser_email' => 'nullable|email|max:150',
            'package_id'      => 'nullable|integer|exists:event_packages,id',
            'package_price'   => 'nullable|numeric|min:0|max:100000000',
            'hall_rate_type'  => 'required|in:hourly,half_day,full_day,custom',
            'hall_charge'     => 'nullable|required_if:hall_rate_type,custom|numeric|min:0|max:1000000000',
            'discount'        => 'nullable|numeric|min:0|max:1000000000',
            'deposit_due'     => 'nullable|numeric|min:0|max:1000000000',
            'requirements'    => 'nullable|string|max:5000',
            'internal_notes'  => 'nullable|string|max:1000',
        ]);
        $data['starts_at'] = $data['date'] . ' ' . $data['start_time'];
        $data['ends_at'] = ($data['end_date'] ?? $data['date']) . ' ' . $data['end_time'];

        return $data;
    }
}
