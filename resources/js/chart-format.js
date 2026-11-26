const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

function trimNumber(value) {
    return value.replace(/\.0+$|(\.\d*?)0+$/, '$1');
}

export function formatBytes(value) {
    const number = Number(value);

    if (!Number.isFinite(number)) {
        return '—';
    }

    if (Math.abs(number) < 1024) {
        return `${Math.round(number)} B`;
    }

    let scaled = Math.abs(number);
    let unit = 0;

    while (scaled >= 1024 && unit < BYTE_UNITS.length - 1) {
        scaled /= 1024;
        unit++;
    }

    const decimals = scaled >= 100 ? 0 : scaled >= 10 ? 1 : 2;

    return `${number < 0 ? '-' : ''}${trimNumber(scaled.toFixed(decimals))} ${BYTE_UNITS[unit]}`;
}

export function formatRate(value) {
    return `${formatBytes(value)}/s`;
}

export function formatLoad(value) {
    const number = Number(value);

    return Number.isFinite(number) ? number.toFixed(2) : '—';
}

export function formatMetricValue(value, unit) {
    if (value === null || value === undefined) {
        return '—';
    }

    switch (unit) {
        case 'bytes':
            return formatBytes(value);
        case 'rate':
            return formatRate(value);
        case 'load':
            return formatLoad(value);
        case 'percent':
            return `${Math.round(Number(value))}%`;
        default:
            return String(value);
    }
}
