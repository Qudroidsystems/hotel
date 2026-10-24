@if (session('success'))
    <div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>
@endif
@if (session('error'))
    <div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ session('error') }}</div></div>
@endif
@if ($errors->any())
    <div class="cb-banner danger"><i class="ri-error-warning-line"></i>
        <div>@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    </div>
@endif

@if (session('receipt'))
    <div class="cb-banner info"><i class="ri-receipt-line"></i>
        <div class="d-flex flex-wrap gap-2 align-items-center w-100"><span>Receipt ready for the guest.</span>
            <a href="{{ route('payments.receipt', session('receipt')) }}" target="_blank" rel="noopener" class="action-btn btn-open ms-auto"><i class="ri-printer-line"></i> Print receipt</a></div>
    </div>
@endif
