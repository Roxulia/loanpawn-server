@props([
    'title',
    'description' => null,
    'class' => '',
])

<section {{ $attributes->class(['panel dashboard-card summary-card', $class]) }}>
    <div class="section-heading">
        <div>
            <h2>{{ $title }}</h2>
            @if ($description)
                <p>{{ $description }}</p>
            @endif
        </div>
    </div>

    {{ $slot }}
</section>
