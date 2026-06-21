@props([
    'tone' => 'neutral',
])

<span {{ $attributes->class(['status-chip', $tone]) }}>
    {{ $slot }}
</span>
