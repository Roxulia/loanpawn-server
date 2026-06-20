function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (character) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[character];
    });
}

function attachmentsHtml(attachments) {
    if (! attachments || attachments.length === 0) {
        return '';
    }

    const links = attachments.map(function (attachment) {
        return `
            <a class="ticket-attachment-link" href="${escapeHtml(attachment.url)}" target="_blank" rel="noopener">
                <span>${escapeHtml(attachment.name)}</span>
                <small>${escapeHtml(attachment.type)}</small>
            </a>
        `;
    }).join('');

    return `
        <div class="ticket-message-attachments">${links}</div>
    `;
}

function appendMessage(thread, currentSender, message) {
    if (! thread || message.senderType === currentSender || thread.querySelector(`[data-message-id="${message.id}"]`)) {
        return;
    }

    const article = document.createElement('article');
    article.className = 'ticket-message ticket-live-highlight';
    article.dataset.messageId = message.id;
    article.innerHTML = `
        <div class="ticket-message-bubble">
            <div class="ticket-message-meta">
                <span class="sender">${escapeHtml(message.senderLabel)}</span>
                <time>${escapeHtml(message.createdAt)}</time>
            </div>
            <p class="ticket-message-text">${escapeHtml(message.message)}</p>
            ${attachmentsHtml(message.attachments)}
        </div>
    `;
    thread.appendChild(article);
    thread.scrollTop = thread.scrollHeight;
}

let supportTicketShowsInitialized = false;

function initSupportTicketShows() {
    if (supportTicketShowsInitialized) {
        return;
    }

    if (! window.Echo) {
        console.warn('Echo not available, support ticket show listener disabled');
        return;
    }

    const ticketShows = document.querySelectorAll('[data-support-ticket-show]');
    console.log('Support ticket show listener loaded:', ticketShows.length);

    if (ticketShows.length === 0) {
        return;
    }

    supportTicketShowsInitialized = true;

    ticketShows.forEach(function (thread) {
        const ticketId = thread.dataset.ticketId;
        const currentSender = thread.dataset.currentSender;
        const statusBadge = document.querySelector(thread.dataset.statusSelector);

        if (! ticketId || ! currentSender) {
            return;
        }

        window.Echo.private('platform.support-ticket.' + ticketId)
            .subscribed(function () {
                console.log('Subscribed to channel: platform.support-ticket.' + ticketId);
            })
            .listen('.platform.support-ticket.message.created', function (event) {
                appendMessage(thread, currentSender, event.message);
            })
            .listen('.platform.support-ticket.status.changed', function (event) {
                if (statusBadge) {
                    statusBadge.textContent = event.ticket.status;
                }
            });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSupportTicketShows, { once: true });
} else {
    initSupportTicketShows();
}
