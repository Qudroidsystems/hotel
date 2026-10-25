{{-- In <head>: the Qlouds styles, and skip the splash when it already played in this browser session or in the Windows app (it has its own). --}}
<link href="{{ asset('qlouds/qlouds.css') }}?v=1" rel="stylesheet">
<script>try { if (/SmartHotelDesktop\//.test(navigator.userAgent) || sessionStorage.getItem(@json('qlouds-splash-' . ($key ?? 'app')))) document.documentElement.classList.add('qlouds-seen'); } catch (e) {}</script>
