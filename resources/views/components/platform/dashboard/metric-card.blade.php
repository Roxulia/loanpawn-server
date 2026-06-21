@props([
    'label',
    'value',
    'subtext' => null,
    'trend' => null,
    'tone' => 'primary',
    'progress' => null,
    'bars' => [],
])

@php
    $toneClass = match ($tone) {
        'cyan' => 'accent-cyan',
        'slate' => 'accent-slate',
        'warning' => 'accent-warning',
        default => '',
    };
    $trendValue = $trend === null ? null : (float) $trend;
@endphp

<div {{ $attributes->class(['panel dashboard-card metric-card', $toneClass]) }}>
    <div>
        <p class="metric-label">{{ $label }}</p>
        <p class="metric-value">{{ $value }}</p>
    </div>

    @if ($progress !== null)
        <div class="metric-progress" style="--progress-value: {{ max(0, min(100, (float) $progress)) }}%">
            <span></span>
        </div>
    @endif

    @if ($bars !== [])
        <div class="metric-sparkbars" aria-hidden="true">
            @foreach ($bars as $bar)
                <span style="--bar-value: {{ max(12, min(100, (float) $bar)) }}%"></span>
            @endforeach
        </div>
    @endif

    <div>
        @if ($trendValue !== null)
            <span class="metric-trend {{ $trendValue < 0 ? 'is-negative' : 'is-positive' }}">{{ number_format($trendValue, 2) }}%</span>
        @endif
        @if ($subtext)
            <p class="metric-subtext">{{ $subtext }}</p>
        @endif
    </div>
</div>
