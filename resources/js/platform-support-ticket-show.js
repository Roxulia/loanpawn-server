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

    const rows = attachments.map(function (attachment) {
        return `
            <tr>
                <td><a href="${escapeHtml(attachment.url)}" target="_blank" rel="noopener">${escapeHtml(attachment.name)}</a></td>
                <td>${escapeHtml(attachment.type)}</td>
            </tr>
        `;
    }).join('');

    return `
        <div class="table-wrap">
            <table>
                <thead><tr><th>Attachment</th><th>Type</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
    `;
}

function appendMessage(thread, currentSender, message) {
    if (! thread || message.senderType === currentSender || thread.querySelector(`[data-message-id="${message.id}"]`)) {
        return;
    }

    const article = document.createElement('article');
    article.className = 'panel ticket-live-highlight';
    article.dataset.messageId = message.id;
    article.innerHTML = `
        <p class="metric-label">
            ${escapeHtml(message.senderLabel)}
            <span class="muted">- ${escapeHtml(message.createdAt)}</span>
        </p>
        <p style="white-space: pre-wrap;">${escapeHtml(message.message)}</p>
        ${attachmentsHtml(message.attachments)}
    `;
    thread.appendChild(article);
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
