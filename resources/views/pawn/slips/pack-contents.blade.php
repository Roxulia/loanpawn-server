@foreach ($children as $child)
    <div style="margin-top: 1mm; font-weight: normal; white-space: normal; overflow-wrap: anywhere;">
        {{ $child['name'] }} ({{ $child['quantity'] }})
        @if ($child['material'])
            | {{ $child['material'] }}
        @endif
        @if ($child['kyat'] !== null || $child['pal'] !== null || $child['yway'] !== null)
            | {{ $child['kyat'] ?? '-' }} kyat {{ $child['pal'] ?? '-' }} pal {{ $child['yway'] ?? '-' }} yway
        @endif
    </div>
@endforeach
