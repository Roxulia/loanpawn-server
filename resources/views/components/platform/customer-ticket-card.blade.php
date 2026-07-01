@props(['ticket'])

<article class="lp-ticket-card" data-ticket-card-id="{{ $ticket->id }}">
    <div class="lp-ticket-card-tags">
        <span class="lp-ticket-type">{{ __('app.support.view.types.'.$ticket->type) }}</span>
        <span class="mobile-card-kicker">{{ $ticket->code }}</span>
    </div>

    <h2 class="mobile-card-title">
        {{ $ticket->subject }}
        <span class="ticket-unread-badge" data-field="unread" @if ((int) $ticket->user_unread_replies_count === 0) hidden @endif>
            {{ $ticket->user_unread_replies_count }}
        </span>
    </h2>

    <div class="lp-ticket-card-footer">
        <div class="lp-inline-meta">
            <span aria-hidden="true">◷</span>
            <strong><time datetime="{{ $ticket->created_at?->toISOString() }}" data-local-time="date">{{ $ticket->created_at?->format('Y-m-d') ?? '-' }}</time></strong>
        </div>
        <div class="lp-ticket-actions">
            <span class="badge">{{ $ticket->status }}</span>
            <a href="{{ route('platform.customer-service.show', $ticket->code) }}" class="button primary">{{ __('app.common.view.actions.view') }}</a>
        </div>
    </div>
</article>
