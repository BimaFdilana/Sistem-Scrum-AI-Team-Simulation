{{-- Dock aktivitas mengambang: pil + panel proses eksekusi, aktif di semua halaman workspace. --}}
<link rel="stylesheet" href="{{ asset('css/activity-dock.css') }}?v={{ filemtime(public_path('css/activity-dock.css')) }}">
<div id="activity-dock" class="activity-dock" data-endpoint="{{ route('activity.active') }}" data-csrf="{{ csrf_token() }}" data-chat-url="{{ route('chat.index', [], false) }}"></div>
<script src="{{ asset('js/activity-dock.js') }}?v={{ filemtime(public_path('js/activity-dock.js')) }}" defer></script>
