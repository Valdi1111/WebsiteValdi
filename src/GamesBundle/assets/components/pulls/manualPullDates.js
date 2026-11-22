import dayjs from "dayjs";

/**
 * Wire format of pull dates: naive game time, no offset. Never use toISOString() for these.
 */
export const PULL_DATE_FORMAT = "YYYY-MM-DD HH:mm:ss";

/**
 * Parses a "YYYY-MM-DD HH:mm:ss" string as a naive local time (no timezone conversion).
 *
 * @param {string|null|undefined} value
 * @returns {import("dayjs").Dayjs|null}
 */
export function parsePullDate(value) {
    if (!value) {
        return null;
    }
    const date = dayjs(value);
    return date.isValid() ? date : null;
}

/**
 * @param {import("dayjs").Dayjs|null|undefined} date
 * @returns {string|null}
 */
export function formatPullDate(date) {
    return date ? date.format(PULL_DATE_FORMAT) : null;
}

/**
 * Inclusive range (second precision) allowed for a manual pull date: strictly after insertAfter,
 * strictly before insertBefore (null bounds = unbounded) and, unless now is null, not in the future.
 *
 * @param {string|null} insertAfter
 * @param {string|null} insertBefore
 * @param {import("dayjs").Dayjs|null} [now] null = no future cap
 * @returns {{min: import("dayjs").Dayjs|null, max: import("dayjs").Dayjs|null}}
 */
export function getAllowedDateRange(insertAfter, insertBefore, now = dayjs()) {
    const after = parsePullDate(insertAfter);
    const before = parsePullDate(insertBefore);

    const min = after ? after.add(1, "second") : null;
    let max = now ? now.millisecond(0) : null;
    if (before) {
        const lastBefore = before.subtract(1, "second");
        if (!max || lastBefore.isBefore(max)) {
            max = lastBefore;
        }
    }

    return { min, max };
}

/**
 * Whether [start, end] contains at least one instant of the allowed range.
 */
function overlapsRange(start, end, range) {
    return (!range.min || !end.isBefore(range.min)) && (!range.max || !start.isAfter(range.max));
}

/**
 * @param {import("dayjs").Dayjs|null|undefined} date
 * @param {{min: import("dayjs").Dayjs|null, max: import("dayjs").Dayjs|null}} range
 * @returns {boolean}
 */
export function isDateInRange(date, range) {
    if (!date || !date.isValid()) {
        return false;
    }
    const second = date.millisecond(0);
    return overlapsRange(second, second, range);
}

/**
 * DatePicker disabledDate callback. Compares at day granularity: the boundary days stay enabled
 * and disabledTime restricts their hours/minutes/seconds.
 */
export function buildDisabledDate(range) {
    return (current) => {
        if (!current) {
            return false;
        }
        if (range.min && current.isBefore(range.min, "day")) {
            return true;
        }
        return Boolean(range.max && current.isAfter(range.max, "day"));
    };
}

function listDisabled(count, toInterval, range) {
    const disabled = [];
    for (let i = 0; i < count; i++) {
        const [start, end] = toInterval(i);
        if (!overlapsRange(start, end, range)) {
            disabled.push(i);
        }
    }
    return disabled;
}

/**
 * DatePicker disabledTime callback: disables every hour/minute/second of the given day
 * that has no instant inside the allowed range.
 */
export function buildDisabledTime(range) {
    return (current) => {
        if (!current) {
            return {};
        }
        const day = current.startOf("day");

        return {
            disabledHours: () => listDisabled(24, (hour) => {
                const start = day.hour(hour);
                return [start, start.endOf("hour")];
            }, range),
            disabledMinutes: (hour) => listDisabled(60, (minute) => {
                const start = day.hour(hour).minute(minute);
                return [start, start.endOf("minute")];
            }, range),
            disabledSeconds: (hour, minute) => listDisabled(60, (second) => {
                const instant = day.hour(hour).minute(minute).second(second);
                return [instant, instant];
            }, range),
        };
    };
}
