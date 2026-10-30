{{-- A phone showing the app's picture, or the hotel's name when none is uploaded. --}}
<div class="phone" aria-hidden="true">
    <div class="screen">
        @if ($shot = \App\Support\Website::image('apps.image'))
            <img src="{{ $shot }}" alt="" loading="lazy">
        @else
            <div class="mock"><span class="t">{{ config('hotel.name') }}</span><span class="b"></span><span class="b s"></span><span class="row"><i class="ri-hotel-bed-line"></i><i class="ri-qr-code-line"></i><i class="ri-restaurant-line"></i><i class="ri-chat-3-line"></i></span><span class="card"></span><span class="card"></span></div>
        @endif
    </div>
</div>
