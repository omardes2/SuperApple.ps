@props(['name' => 'dot'])

@php
    $paths = [
        'home' => '<path d="M3 9.5 12 3l9 6.5"/><path d="M5 10v10h14V10"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 6a3 3 0 0 1 0 6"/><path d="M21 20a5 5 0 0 0-4-4.9"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'doc' => '<path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5"/>',
        'invoice' => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'cash' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
        'repeat' => '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'minus' => '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
        'truck' => '<path d="M1 6h13v9H1z"/><path d="M14 9h4l3 3v3h-7z"/><circle cx="5.5" cy="17.5" r="1.5"/><circle cx="17.5" cy="17.5" r="1.5"/>',
        'bank' => '<path d="M3 10 12 4l9 6"/><path d="M5 10v8M9 10v8M15 10v8M19 10v8"/><path d="M3 20h18"/>',
        'book' => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/>',
        'badge' => '<circle cx="12" cy="8" r="4"/><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h14v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M16 12h3"/>',
        'chat' => '<path d="M4 4h16v12H7l-3 3z"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15l3-4 3 3 4-6"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'cog' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'bell' => '<path d="M6 9a6 6 0 0 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9z"/><path d="M10 21a2 2 0 0 0 4 0"/>',
        'dot' => '<circle cx="12" cy="12" r="3"/>',
    ];
    $svg = $paths[$name] ?? $paths['dot'];
@endphp

<svg {{ $attributes->merge(['class' => 'h-5 w-5', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'viewBox' => '0 0 24 24']) }}>
    {!! $svg !!}
</svg>
