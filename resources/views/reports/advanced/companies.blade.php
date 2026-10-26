@php $T = $data['totals']; @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Billed to companies" :value="$fmt($T['billed'])" icon="ri-file-list-3-line" accent="teal" :hint="$T['nights'] . ' room nights'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Paid by companies" :value="$fmt($T['paid'])" icon="ri-hand-coin-line" accent="green" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Owed now" :value="$fmt($T['owes'])" icon="ri-building-2-line" accent="sky" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Overdue now" :value="$fmt($T['overdue'])" icon="ri-time-line" accent="rose" /></div>
</div>
<x-cb.card title="Companies" icon="ri-building-2-line" :flush="true">
    <div class="table-responsive">
        <table class="cb-table stack">
            <thead><tr><th>Company</th><th class="num">Stays</th><th class="num">Room nights</th><th class="num">Events</th><th class="num">Billed</th><th class="num">Paid</th><th class="num">Owes now</th><th class="num">Overdue</th></tr></thead>
            <tbody>
                @forelse ($data['rows'] as $x)
                    @php $over = $x['limit'] > 0 && $x['owes'] > $x['limit']; @endphp
                    <tr>
                        <td data-label="Company">@can('View company accounts')<a href="{{ route('companies.show', $x['company']) }}" class="fw-semibold">{{ $x['company']->name }}</a>@else<span class="fw-semibold">{{ $x['company']->name }}</span>@endcan
                            <div class="small text-muted">{{ $x['company']->account_number }}{{ $over ? ' · over its limit' : '' }}</div></td>
                        <td data-label="Stays" class="num">{{ $x['stays'] }}</td><td data-label="Room nights" class="num">{{ $x['nights'] }}</td><td data-label="Events" class="num">{{ $x['events'] }}</td>
                        <td data-label="Billed" class="num">{{ $fmt($x['billed']) }}</td><td data-label="Paid" class="num">{{ $fmt($x['paid']) }}</td>
                        <td data-label="Owes now" class="num fw-semibold">{{ $fmt($x['owes']) }}</td>
                        <td data-label="Overdue" class="num {{ $x['overdue'] > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ $x['overdue'] > 0 ? $fmt($x['overdue']) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted">No company activity in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-cb.card>
