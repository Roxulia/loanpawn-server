@props([
    'label',
    'current',
    'limit',
    'percent',
    'tone' => 'primary',
])

<div {{ $attributes->class(['usage-item']) }}>
    <div class="usage-item-head">
        <strong>{{ $label }}</strong>
        <span>{{ $current }} / {{ $limit }}</span>
    </div>
    <div class="usage-track {{ $tone }}" style="--usage-value: {{ max(0, min(100, (float) $percent)) }}%">
        <span></span>
    </div>
</div>
