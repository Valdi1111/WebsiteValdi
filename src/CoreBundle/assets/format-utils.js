import { DateTime } from "luxon";

export function formatDateFromIso(text, defaultText = null) {
    if (!text) {
        return defaultText ?? text;
    }
    return DateTime.fromISO(text).setLocale('it').toLocaleString(DateTime.DATE_MED);
}

export function formatDateTimeFromIso(text, defaultText = null) {
    if (!text) {
        return defaultText ?? text;
    }
    return DateTime.fromISO(text).setLocale('it').toLocaleString(DateTime.DATETIME_MED);
}

export function formatDateFromTimestamp(seconds, defaultText = null) {
    if (!seconds) {
        return defaultText ?? seconds;
    }
    return DateTime.fromSeconds(seconds).setLocale('it').toLocaleString(DateTime.DATE_MED);
}

export function formatDateTimeFromTimestamp(seconds, defaultText = null) {
    if (!seconds) {
        return defaultText ?? seconds;
    }
    return DateTime.fromSeconds(seconds).setLocale('it').toLocaleString(DateTime.DATETIME_MED);
}

const locale = 'en-US';
const opts = {
    style: 'percent',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
};
const formatter = new Intl.NumberFormat(locale, opts);

export function formatPercent(amount, total) {
    return formatter.format((!amount || !total) ? 0 : (amount / total));
}

export function formatBytes(bytes) {
    let i = 0;
    if (bytes) {
        i = Math.floor(Math.log(bytes) / Math.log(1024));
    }
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    return (bytes / Math.pow(1024, i)).toFixed(2) * 1 + ' ' + sizes[i];
}