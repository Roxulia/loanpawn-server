function dateParts(value, includeTime) {
    if (! value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const options = {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    };

    if (includeTime) {
        options.hour = '2-digit';
        options.minute = '2-digit';
        options.hour12 = false;
    }

    const parts = new Intl.DateTimeFormat(undefined, options)
        .formatToParts(date)
        .reduce(function (result, part) {
            result[part.type] = part.value;
            return result;
        }, {});

    const dateText = `${parts.year}-${parts.month}-${parts.day}`;

    if (! includeTime) {
        return dateText;
    }

    return `${dateText} ${parts.hour}:${parts.minute}`;
}

export function formatLocalDateTime(value) {
    return dateParts(value, true) ?? '-';
}

export function formatLocalDate(value) {
    return dateParts(value, false) ?? '-';
}

export function initLocalTime(root = document) {
    root.querySelectorAll('[data-local-time]').forEach(function (element) {
        const value = element.getAttribute('datetime') || element.dataset.datetime;
        const mode = element.dataset.localTime;
        const formatted = mode === 'date'
            ? formatLocalDate(value)
            : formatLocalDateTime(value);

        if (formatted !== '-') {
            element.textContent = formatted;
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        initLocalTime();
    }, { once: true });
} else {
    initLocalTime();
}
