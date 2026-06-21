@props([
    'label',
    'value',
])

<div {{ $attributes->class(['compact-row']) }}>
    <span>{{ $label }}</span>
    <strong>{{ $value }}</strong>
</div>
