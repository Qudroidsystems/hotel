{{-- Tickets for an event the public pays to attend. --}}
@php
    use App\Enums\PaymentMethod;
    $types = $e->ticketTypes;
    $seatsSold = $e->ticketSeatsSold();
    $cap = $e->ticketCapacity();
    $ticketOrders = $e->ticketOrders()->with('reservation')->whereIn('status', ['paid', 'cancelled'])->limit(15)->get();
    $promos = $e->ticketPromos;
    $chan = ['app' => ' · guest app', 'web' => ' · online', 'desk' => ''];
    $blocker = $e->ticketSalesBlocker();
@endphp
<x-cb.card title="Tickets" icon="ri-coupon-3-line" :flush="true">
    <x-slot:tools>
        @if ($e->is_ticketed)
            @can('Sell event tickets')@if (! $blocker)<button type="button" class="action-btn btn-go" data-bs-toggle="modal" data-bs-target="#sellModal"><i class="ri-shopping-cart-2-line"></i> Sell tickets</button>@endif @endcan
            @canany(['Check event tickets', 'Sell event tickets'])<a class="action-btn btn-more" href="{{ route('events.tickets.door') }}"><i class="ri-qr-scan-2-line"></i> Door</a>@endcanany
            @can('Manage events')
                @if ($e->status === 'confirmed')
                    <form method="POST" action="{{ route('events.tickets.sales', $e) }}" class="d-inline">@csrf<input type="hidden" name="open" value="{{ $e->ticket_sales_open ? 0 : 1 }}"><button class="action-btn btn-more">{{ $e->ticket_sales_open ? 'Close sales' : 'Open sales' }}</button></form>
                @endif
            @endcan
        @endif
        @can('Manage events')<button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#ticketSetupModal"><i class="ri-settings-3-line"></i></button>@endcan
    </x-slot:tools>
    @if (! $e->is_ticketed)
        <div class="p-3 small text-muted">No tickets. For a concert, show, gala or any event people pay to attend, turn tickets on (<i class="ri-settings-3-line"></i>), add ticket types and open sales. Each ticket gets a QR code that is scanned once at the door.</div>
    @else
        <div class="px-3 pt-3 small d-flex flex-wrap gap-3">
            <span><strong>{{ $seatsSold }}</strong>{{ $cap ? ' of ' . $cap : '' }} places sold</span>
            <span><strong>{{ $e->tickets()->where('status', 'used')->count() }}</strong> in at the door</span>
            <span>Ticket money <strong>{{ $fmt($e->ticketRevenue()) }}</strong> <span class="text-muted">(not part of the hall bill)</span></span>
            <span class="{{ $blocker ? 'text-warning-emphasis' : 'text-success' }}"><i class="{{ $blocker ? 'ri-lock-line' : 'ri-checkbox-circle-line' }}"></i> {{ $blocker ?? 'On sale' }}</span>
            @unless ($blocker)<span><i class="ri-links-line"></i> Online: <a href="{{ route('tickets.shop', $e->id) }}" target="_blank" rel="noopener">{{ route('tickets.shop', $e->id) }}</a></span>@endunless
        </div>
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Ticket</th><th class="num">Price</th><th class="num">Sold</th><th class="num">Left</th><th></th></tr></thead>
                <tbody>
                    @forelse ($types as $t)
                        <tr style="{{ $t->is_active ? '' : 'opacity:.55' }}">
                            <td data-label="Ticket"><strong>{{ $t->name }}</strong>{{ $t->admits > 1 ? ' · admits ' . $t->admits : '' }}@if ($t->description)<div class="small text-muted">{{ $t->description }}</div>@endif
                                @if ($t->sales_start_at || $t->sales_end_at)<div class="small text-muted">On sale {{ $t->sales_start_at ? 'from ' . $t->sales_start_at->format('j M g:i A') : '' }} {{ $t->sales_end_at ? 'until ' . $t->sales_end_at->format('j M g:i A') : '' }}</div>@endif</td>
                            <td data-label="Price" class="num">{{ $fmt($t->priceNow()) }}@if ($t->isEarlyBird())<div class="small text-muted">early bird until {{ $t->early_until->format('j M') }}, then {{ $fmt($t->price) }}</div>@endif</td>
                            <td data-label="Sold" class="num">{{ $t->sold() }}</td>
                            <td data-label="Left" class="num">{{ $t->left() ?? '—' }}</td>
                            <td class="text-end">@can('Manage events')<button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#typeModal{{ $t->id }}" title="Edit"><i class="ri-pencil-line"></i></button>@endcan</td>
                        </tr>
                    @empty
                        <tr><td class="text-muted small" colspan="5">No ticket types yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @can('Manage events')<div class="px-3 pb-2"><button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#typeModal0"><i class="ri-add-line"></i> Add a ticket type</button></div>@endcan
        <div class="px-3 pb-3 border-top pt-2">
            <div class="d-flex justify-content-between align-items-center"><strong class="small">Promo codes</strong>
                @can('Manage events')<button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="modal" data-bs-target="#promoModal"><i class="ri-add-line"></i> Add a code</button>@endcan</div>
            @forelse ($promos as $p)
                <div class="d-flex justify-content-between align-items-center small py-1 {{ $p->closedReason() ? 'text-muted' : '' }}">
                    <span><code class="fw-bold">{{ $p->code }}</code> · {{ $p->label }} · used {{ $p->used }}{{ $p->max_uses !== null ? ' of ' . $p->max_uses : '' }}{{ $p->ends_at ? ' · until ' . $p->ends_at->format('j M') : '' }}{{ $p->closedReason() ? ' · ' . rtrim($p->closedReason(), '.') : '' }}</span>
                    @can('Manage events')<form method="POST" action="{{ route('events.tickets.promos.toggle', [$e, $p]) }}">@csrf<button class="btn btn-sm btn-link p-0">{{ $p->is_active ? 'Stop' : 'Restart' }}</button></form>@endcan
                </div>
            @empty
                <div class="small text-muted">None. A code gives money off an order (a percentage or an amount), online, in the app and at the desk.</div>
            @endforelse
        </div>
        @if ($ticketOrders->isNotEmpty())
            <div class="table-responsive border-top">
                <table class="cb-table stack">
                    <tbody>
                        @foreach ($ticketOrders as $o)
                            <tr style="{{ $o->isCancelled() ? 'opacity:.5' : '' }}">
                                <td data-label="Order" class="small"><a href="{{ route('events.tickets.order', $o) }}">{{ $o->number }}</a> · {{ $o->paid_at?->format('j M, g:i A') }}<div class="text-muted">{{ $o->buyer_name }}{{ $chan[$o->channel] ?? '' }}</div></td>
                                <td data-label="Paid" class="small">{{ $o->quantity }} × · {{ $o->method_label }}{{ $o->reservation ? ' (' . $o->reservation->reference . ')' : '' }}@if ($o->isCancelled())<div class="text-danger">Cancelled: {{ $o->cancel_reason }}</div>@endif</td>
                                <td data-label="Total" class="num fw-semibold">{{ $fmt($o->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-3 pb-3 small"><a href="{{ route('events.tickets.index', ['event' => $e->id]) }}">All orders for this event</a></div>
        @endif
    @endif
</x-cb.card>

@can('Manage events')
<div class="modal fade" id="ticketSetupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('events.tickets.configure', $e) }}">@csrf
            <div class="modal-header"><h5 class="modal-title">Tickets for {{ $e->title }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><input type="hidden" name="is_ticketed" value="0"><label class="form-check"><input type="checkbox" class="form-check-input" name="is_ticketed" value="1" @checked($e->is_ticketed)> People buy tickets to attend this event</label></div>
                <div class="col-12"><label for="ts_info">What buyers should know</label><textarea name="ticket_info" id="ts_info" class="form-control" rows="3" maxlength="1000" placeholder="Line-up, dress code, doors open at 6 PM, no refunds after…">{{ $e->ticket_info }}</textarea><div class="small text-muted mt-1">Shown on each ticket and in the guest app. At most {{ $cap ?: '—' }} people in all (the hall's capacity for this layout).</div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>
@if ($e->is_ticketed)
@foreach ($types->concat([new \App\Models\Events\EventTicketType()]) as $t)
<div class="modal fade" id="typeModal{{ $t->id ?? 0 }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ $t->exists ? route('events.tickets.types.update', [$e, $t]) : route('events.tickets.types.store', $e) }}">@csrf @if ($t->exists) @method('PUT') @endif
            <div class="modal-header"><h5 class="modal-title">{{ $t->exists ? 'Edit ' . $t->name : 'New ticket type' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-7"><label>Name</label><input name="name" class="form-control" maxlength="80" required value="{{ $t->name }}" placeholder="Regular, VIP, Table for 10"></div>
                <div class="col-sm-5"><label>Price</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="price" class="form-control" required value="{{ $t->exists ? number_format((float) $t->price, 2, '.', '') : '' }}"></div></div>
                <div class="col-12"><label>What it includes</label><input name="description" class="form-control" maxlength="255" value="{{ $t->description }}" placeholder="e.g. Front rows, one drink"></div>
                <div class="col-4"><label>Admits</label><input type="number" min="1" name="admits" class="form-control" value="{{ $t->admits ?? 1 }}"></div>
                <div class="col-4"><label>For sale</label><input type="number" min="1" name="quantity" class="form-control" value="{{ $t->quantity }}" placeholder="No limit"></div>
                <div class="col-4"><label>Max per order</label><input type="number" min="1" name="max_per_order" class="form-control" value="{{ $t->max_per_order ?? 10 }}"></div>
                <div class="col-sm-6"><label>Early-bird price</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="early_price" class="form-control" value="{{ $t->early_price !== null ? number_format((float) $t->early_price, 2, '.', '') : '' }}" placeholder="None"></div></div>
                <div class="col-sm-6"><label>Early bird until</label><input type="datetime-local" name="early_until" class="form-control" value="{{ $t->early_until?->format('Y-m-d\TH:i') }}"></div>
                <div class="col-sm-6"><label>Sales start</label><input type="datetime-local" name="sales_start_at" class="form-control" value="{{ $t->sales_start_at?->format('Y-m-d\TH:i') }}"></div>
                <div class="col-sm-6"><label>Sales end</label><input type="datetime-local" name="sales_end_at" class="form-control" value="{{ $t->sales_end_at?->format('Y-m-d\TH:i') }}"></div>
                <div class="col-12"><input type="hidden" name="is_active" value="0"><label class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($t->is_active ?? true)> On sale</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>
@endforeach
<div class="modal fade" id="promoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('events.tickets.promos.store', $e) }}">@csrf
            <div class="modal-header"><h5 class="modal-title">New promo code</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-6"><label>Code</label><input name="code" class="form-control text-uppercase" maxlength="30" required pattern="[A-Za-z0-9_-]+" placeholder="EARLY10"></div>
                <div class="col-sm-6"><label>Money off</label><div class="input-group"><input type="number" step="0.01" min="0.01" name="value" class="form-control" required><select name="kind" class="form-select" style="max-width:110px"><option value="percent">%</option><option value="amount">{{ $sym }} off</option></select></div></div>
                <div class="col-sm-4"><label>Orders it works for</label><input type="number" min="1" name="max_uses" class="form-control" placeholder="No limit"></div>
                <div class="col-sm-4"><label>From</label><input type="datetime-local" name="starts_at" class="form-control"></div>
                <div class="col-sm-4"><label>Until</label><input type="datetime-local" name="ends_at" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add code</button></div>
        </form>
    </div>
</div>
@endif
@endcan

@can('Sell event tickets')
@if ($e->is_ticketed && ! $blocker)
@php $inHouse = \App\Http\Controllers\Events\TicketController::inHouse(); $bankAccounts ??= collect(); @endphp
<div class="modal fade" id="sellModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content rm-form" method="POST" action="{{ route('events.tickets.sell', $e) }}" id="sellForm">@csrf
            <input type="hidden" name="key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="modal-header"><h5 class="modal-title">Sell tickets · {{ $e->title }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12">
                    @foreach ($types->where('is_active', true) as $t)
                        @php $why = $t->closedReason(); @endphp
                        <div class="d-flex align-items-center justify-content-between border-bottom py-2 gap-2">
                            <div><strong>{{ $t->name }}</strong> <span class="text-muted">{{ $fmt($t->priceNow()) }}{{ $t->isEarlyBird() ? ' early bird' : '' }}{{ $t->admits > 1 ? ' · admits ' . $t->admits : '' }}</span>@if ($why)<div class="small text-warning-emphasis">{{ ucfirst($why) }}</div>@elseif ($t->left() !== null)<div class="small text-muted">{{ $t->left() }} left</div>@endif</div>
                            <input type="number" min="0" max="{{ min($t->max_per_order, $t->left() ?? $t->max_per_order) }}" name="lines[{{ $t->id }}]" class="form-control form-control-sm tk-qty" style="width:90px" value="0" data-price="{{ $t->priceNow() }}" @disabled($why)>
                        </div>
                    @endforeach
                    <div class="text-end mt-2 fw-bold">Total <span id="tkTotal">{{ $sym }}0.00</span>@if (! config('hotel.prices_include_tax') && config('hotel.taxes')) <span class="small text-muted fw-normal">+ tax</span>@endif</div>
                </div>
                @if ($promos->isNotEmpty())<div class="col-sm-4"><label>Promo code</label><input name="promo" class="form-control text-uppercase" maxlength="30" value="{{ old('promo') }}"><div class="small text-muted">Taken off the total when sold.</div></div><div class="w-100"></div>@endif
                <div class="col-sm-4"><label>Buyer</label><input name="buyer_name" class="form-control" maxlength="120" required value="{{ old('buyer_name') }}"></div>
                <div class="col-sm-4"><label>Phone</label><input name="buyer_phone" class="form-control" maxlength="40" value="{{ old('buyer_phone') }}"></div>
                <div class="col-sm-4"><label>E-mail</label><input type="email" name="buyer_email" class="form-control" maxlength="150" value="{{ old('buyer_email') }}"><div class="small text-muted">The tickets are e-mailed here.</div></div>
                <div class="col-sm-4"><label>Paid by</label><select name="method" class="form-select" id="tkMethod">@foreach (PaymentMethod::takeable() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach @if ($inHouse->isNotEmpty())<option value="room">Charge to a room</option>@endif</select></div>
                <div class="col-sm-4" id="tkRoom" style="display:none"><label>Guest staying</label><select name="reservation_id" class="form-select">@foreach ($inHouse as $r)<option value="{{ $r->id }}">{{ $r->rooms->pluck('room.number')->filter()->implode(', ') }} · {{ $r->guest?->full_name }}</option>@endforeach</select></div>
                <div class="col-sm-4" id="tkRef"><label>Reference</label><input name="reference" class="form-control" maxlength="100"></div>
                @if ($bankAccounts->isNotEmpty())<div class="col-sm-4" id="tkBank"><label>Into account</label><select name="bank_account_id" class="form-select"><option value="">—</option>@foreach ($bankAccounts as $b)<option value="{{ $b->id }}">{{ $b->bank_name }} · {{ $b->account_number }}</option>@endforeach</select></div>@endif
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Sell and issue tickets</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const sym = @json($sym), total = document.getElementById('tkTotal'), method = document.getElementById('tkMethod');
    const sum = () => { let t = 0; document.querySelectorAll('.tk-qty').forEach(i => t += (parseInt(i.value) || 0) * parseFloat(i.dataset.price)); total.textContent = sym + t.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    document.querySelectorAll('.tk-qty').forEach(i => i.addEventListener('input', sum));
    const show = () => { const room = method.value === 'room'; document.getElementById('tkRoom').style.display = room ? '' : 'none'; document.getElementById('tkRef').style.display = room ? 'none' : ''; const b = document.getElementById('tkBank'); if (b) b.style.display = room ? 'none' : ''; };
    method.addEventListener('change', show); show();
    document.getElementById('sellForm').addEventListener('submit', e => { if (e.submitter) e.submitter.disabled = true; });
});
</script>
@endif
@endcan
