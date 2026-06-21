@props([
    'title',
    'body',
    'icon' => null,
    'tone' => 'primary',
])

<article {{ $attributes->class(['insight-card', $tone]) }}>
    <div class="insight-card-head">
        @if ($icon)
            <span class="insight-card-marker" aria-hidden="true"></span>
        @endif
        <strong>{{ $title }}</strong>
    </div>
    <p>{{ $body }}</p>
</article>
