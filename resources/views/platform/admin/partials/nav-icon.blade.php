@switch($icon)
    @case('dashboard') <path d="M3 13h8V3H3v10Zm10 8h8V11h-8v10ZM3 21h8v-6H3v6Zm10-12h8V3h-8v6Z"/> @break
    @case('tenants') <path d="M4 21v-7l8-5 8 5v7h-5v-5H9v5H4Zm3-12V4h4v3.8L7 10.3V9Zm10 1.3-4-2.5V4h4v6.3Z"/> @break
    @case('users') <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/> @break
    @case('plans') <path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/> @break
    @case('features') <path d="M12 2l3 4 5 .7-3.6 3.5.9 4.9-4.3-2.3-4.3 2.3.9-4.9L6 6.7 11 6l1-4Zm-7 15h14v5H5v-5Z"/> @break
    @case('currency') <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 4v12m4-9H9.5a2.5 2.5 0 0 0 0 5H14a2 2 0 0 1 0 4H8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/> @break
    @case('pairs') <path d="M7 7h13l-3-3m3 3-3 3M17 17H4l3 3m-3-3 3-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/> @break
    @case('rates') <path d="M3 18 9 12l4 4 8-10M17 6h4v4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/> @break
    @case('billing') <path d="M3 6h18v12H3zM3 10h18M7 15h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/> @break
    @case('payments') <path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Zm4 5h6m-6 4h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/> @break
    @case('qr') <path d="M3 3h7v7H3V3Zm11 0h7v7h-7V3ZM3 14h7v7H3v-7Zm12 0h2v2h-2v-2Zm4 0h2v4h-2v-4Zm-4 4h4v3h-4v-3Z"/> @break
    @case('support') <path d="M4 4h16v13H8l-4 4V4Zm4 5h8m-8 4h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/> @break
@endswitch
