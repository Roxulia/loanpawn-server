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

function toast(message) {
    let container = document.querySelector('.ticket-toast-container');
    if (! container) {
        container = document.createElement('div');
        container.className = 'ticket-toast-container';
        document.body.appendChild(container);
    }

    const item = document.createElement('div');
    item.className = 'ticket-toast';
    item.textContent = message;
    container.appendChild(item);
    setTimeout(function () { item.remove(); }, 5000);
}

function unreadBadge(ticket) {
    const count = Number(ticket.userUnreadRepliesCount || 0);

    return `<span class="ticket-unread-badge" data-field="unread" ${count === 0 ? 'hidden' : ''}>${escapeHtml(count)}</span>`;
}

function customerRowHtml(ticket) {
    return `
        <td data-label="Created" data-field="created">${escapeHtml(ticket.createdAt)}</td>
        <td data-label="Code" data-field="code">${escapeHtml(ticket.code)}</td>
        <td data-label="Subject" data-field="subject">${escapeHtml(ticket.subject)} ${unreadBadge(ticket)}</td>
        <td data-label="Type" data-field="type">${escapeHtml(ticket.typeLabel)}</td>
        <td data-label="Status"><span class="badge" data-field="status">${escapeHtml(ticket.status)}</span></td>
        <td data-label="Messages" data-field="messages">${escapeHtml(ticket.messagesCount)}</td>
        <td data-label=""><a href="${escapeHtml(ticket.userDetailUrl)}" class="button secondary" data-field="detail">View</a></td>
    `;
}

function adminRowHtml(ticket) {
    return `
        <td data-label="Updated" data-field="updated">${escapeHtml(ticket.updatedAt)}</td>
        <td data-label="Code" data-field="code">${escapeHtml(ticket.code)}</td>
        <td data-label="User" data-field="user">${escapeHtml(ticket.userName)}</td>
        <td data-label="Subject" data-field="subject">${escapeHtml(ticket.subject)}</td>
        <td data-label="Type" data-field="type">${escapeHtml(ticket.typeLabel)}</td>
        <td data-label="Status"><span class="badge" data-field="status">${escapeHtml(ticket.status)}</span></td>
        <td data-label="Messages" data-field="messages">${escapeHtml(ticket.messagesCount)}</td>
        <td data-label=""><a href="${escapeHtml(ticket.adminDetailUrl)}" class="button secondary" data-field="detail">View</a></td>
    `;
}

function upsertTicket(config, ticket) {
    if (! config.body) {
        return;
    }

    config.emptyState?.remove();
    if (config.tableWrap) {
        config.tableWrap.style.display = '';
    }

    let row = config.body.querySelector(`[data-ticket-id="${ticket.id}"]`);
    if (! row) {
        row = document.createElement('tr');
        row.dataset.ticketId = ticket.id;
        config.body.prepend(row);
    }

    row.innerHTML = config.role === 'admin'
        ? adminRowHtml(ticket)
        : customerRowHtml(ticket);
    row.classList.remove('ticket-live-highlight');
    void row.offsetWidth;
    row.classList.add('ticket-live-highlight');
}

function initCustomerIndex(root) {
    const platformUserId = root.dataset.platformUserId;
    if (! platformUserId) {
        return;
    }

    const config = {
        role: 'customer',
        body: document.getElementById(root.dataset.bodyId),
        tableWrap: document.getElementById(root.dataset.tableWrapId),
        emptyState: document.getElementById(root.dataset.emptyStateId),
    };

    window.Echo.private('platform.user.' + platformUserId + '.customer-service')
        .subscribed(function () {
            console.log('Subscribed to channel: platform.user.' + platformUserId + '.customer-service');
        })
        .listen('.platform.support-ticket.message.created', function (event) {
            upsertTicket(config, event.ticket);
            toast('New admin reply in ticket: ' + event.ticket.subject);
        })
        .listen('.platform.support-ticket.status.changed', function (event) {
            upsertTicket(config, event.ticket);
            toast('Ticket status updated: ' + event.ticket.subject);
        });
}

function initAdminIndex(root) {
    const config = {
        role: 'admin',
        body: document.getElementById(root.dataset.bodyId),
        tableWrap: document.getElementById(root.dataset.tableWrapId),
        emptyState: document.getElementById(root.dataset.emptyStateId),
    };

    window.Echo.private('platform.admin.issued-tickets')
        .subscribed(function () {
            console.log('Subscribed to channel: platform.admin.issued-tickets');
        })
        .listen('.platform.support-ticket.created', function (event) {
            upsertTicket(config, event.ticket);
            toast('New support ticket: ' + event.ticket.subject);
        })
        .listen('.platform.support-ticket.message.created', function (event) {
            upsertTicket(config, event.ticket);
            toast('New user reply in ticket: ' + event.ticket.subject);
        });
}

let supportTicketIndexesInitialized = false;

function initSupportTicketIndexes() {
    if (supportTicketIndexesInitialized) {
        return;
    }

    if (! window.Echo) {
        console.warn('Echo not available, support ticket index listener disabled');
        return;
    }

    const ticketIndexes = document.querySelectorAll('[data-support-ticket-index]');
    console.log('Support ticket index listener loaded:', ticketIndexes.length);

    if (ticketIndexes.length === 0) {
        return;
    }

    supportTicketIndexesInitialized = true;

    ticketIndexes.forEach(function (root) {
        if (root.dataset.supportTicketIndex === 'admin') {
            initAdminIndex(root);
            return;
        }

        initCustomerIndex(root);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSupportTicketIndexes, { once: true });
} else {
    initSupportTicketIndexes();
}
