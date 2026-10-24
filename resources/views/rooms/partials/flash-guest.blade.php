@if (session('success'))<div class="notice ok" role="status"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
@if (session('error'))<div class="notice bad" role="alert"><i class="ri-error-warning-line"></i><div>{{ session('error') }}</div></div>@endif
@if ($errors->any())<div class="notice bad" role="alert"><i class="ri-error-warning-line"></i><div>@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
