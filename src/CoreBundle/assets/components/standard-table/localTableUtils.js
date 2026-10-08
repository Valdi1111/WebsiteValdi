import dayjs from "dayjs";

/**
 * Normalizes values for comparison, handling null and undefined.
 *
 * @param {any} val
 * @returns {string}
 */
function normalizeString(val) {
    if (val === null || val === undefined) return "";
    return String(val).toLowerCase().trim();
}

/**
 * Parses and normalizes various date formats into a valid dayjs instance.
 * Handles ISO strings, Unix timestamps in seconds (<= 10 digits), and milliseconds.
 *
 * @param {any} val
 * @returns {dayjs.Dayjs|null}
 */
function parseDateValue(val) {
    if (val === null || val === undefined || val === "") return null;

    let d;
    if (typeof val === "number") {
        // Distinguish between seconds timestamp (standard Unix <= 10 digits) and milliseconds
        d = val < 10000000000 ? dayjs(val * 1000) : dayjs(val);
    } else {
        d = dayjs(val);
    }

    return d.isValid() ? d : null;
}

/**
 * Evaluates whether a single rule matches the field value of a record.
 * Supports all operators declared in filterCatalog.js without requiring external dayjs plugins.
 *
 * @param {any} recordValue The raw value on the record
 * @param {Object} rule Filter rule object { operator, value, valMax }
 * @param {Object} col Column definition
 * @returns {boolean}
 */
export function evaluateFilterRule(recordValue, rule, col = {}) {
    const { operator, value } = rule;
    const filterType = col.filterType;

    // 1. Universal emptiness checks
    if (operator === "empty") {
        if (Array.isArray(recordValue)) return recordValue.length === 0;
        return recordValue === null || recordValue === undefined || recordValue === "";
    }
    if (operator === "notEmpty") {
        if (Array.isArray(recordValue)) return recordValue.length > 0;
        return recordValue !== null && recordValue !== undefined && recordValue !== "";
    }

    // 2. Standard Ant Design enum checkbox and multi-tags operator
    if (operator === "in") {
        const allowedValues = Array.isArray(value) ? value : [value];
        if (allowedValues.length === 0) return true;

        if (Array.isArray(recordValue)) {
            // Tag arrays: matches if there is any intersection
            return recordValue.some((item) => {
                const itemKey = typeof item === "object" && item !== null ? (item.value ?? item.id) : item;
                return allowedValues.includes(itemKey) || allowedValues.includes(String(itemKey));
            });
        }
        return allowedValues.includes(recordValue) || allowedValues.includes(String(recordValue));
    }

    // If operator requires a value and none is provided, consider it matched
    if (value === null || value === undefined || value === "") {
        return true;
    }

    // 3. Date Operators (implemented using native dayjs methods without extra plugins)
    if (filterType === "date" || col.valueType === "date" || col.valueType === "datetime") {
        const recDate = parseDateValue(recordValue);
        if (!recDate) return false;

        const recTime = recDate.valueOf();

        switch (operator) {
            case "dateExact": {
                const target = parseDateValue(value);
                if (!target) return true;
                return recDate.isSame(target, "day");
            }
            case "fromDate": {
                const target = parseDateValue(value);
                if (!target) return true;
                // Greater than or equal to the start of the target day
                return recTime >= target.startOf("day").valueOf();
            }
            case "toDate": {
                const target = parseDateValue(value);
                if (!target) return true;
                // Less than or equal to the end of the target day
                return recTime <= target.endOf("day").valueOf();
            }
            case "dateRange": {
                if (Array.isArray(value) && value.length === 2 && value[0] && value[1]) {
                    const from = parseDateValue(value[0]);
                    const to = parseDateValue(value[1]);
                    if (!from || !to) return true;
                    const fromTime = from.startOf("day").valueOf();
                    const toTime = to.endOf("day").valueOf();
                    return recTime >= fromTime && recTime <= toTime;
                }
                return true;
            }
            default:
                return true;
        }
    }

    // 4. Text Operators
    if (filterType === "text" || typeof recordValue === "string") {
        const strVal = normalizeString(recordValue);
        const searchVal = normalizeString(value);

        switch (operator) {
            case "like":
                return strVal.includes(searchVal);
            case "notLike":
                return !strVal.includes(searchVal);
            case "startsWith":
                return strVal.startsWith(searchVal);
            case "endsWith":
                return strVal.endsWith(searchVal);
            case "exact":
                return strVal === searchVal;
            case "neq":
                return strVal !== searchVal;
            default:
                return strVal.includes(searchVal);
        }
    }

    // 5. Number Operators
    if (filterType === "number" || typeof recordValue === "number") {
        const numVal = Number(recordValue);
        if (Number.isNaN(numVal)) return false;

        switch (operator) {
            case "eq":
                return numVal === Number(value);
            case "neq":
                return numVal !== Number(value);
            case "gt":
                return numVal > Number(value);
            case "gte":
                return numVal >= Number(value);
            case "lt":
                return numVal < Number(value);
            case "lte":
                return numVal <= Number(value);
            case "between": {
                if (Array.isArray(value)) {
                    const min = value[0] !== null && value[0] !== undefined ? Number(value[0]) : -Infinity;
                    const max = value[1] !== null && value[1] !== undefined ? Number(value[1]) : Infinity;
                    return numVal >= min && numVal <= max;
                }
                return true;
            }
            default:
                return true;
        }
    }

    // Fallback equality check
    return recordValue == value;
}

/**
 * Filters an array of records in-memory using active column filter rules.
 * All conditions within a column must pass (AND), and all active columns must match (AND).
 *
 * @param {Array<Object>} data
 * @param {Object} filters Map of column rules { [field]: Array<Rule> }
 * @param {Array<Object>} columns
 * @returns {Array<Object>}
 */
export function filterRecordsLocally(data = [], filters = {}, columns = []) {
    const activeEntries = Object.entries(filters).filter(([_, rules]) => Array.isArray(rules) && rules.length > 0);
    if (activeEntries.length === 0) return data;

    return data.filter((record) => {
        return activeEntries.every(([field, rules]) => {
            const col = columns.find((c) => c.dataIndex === field) || {};
            const recordValue = record[field];

            // Every condition defined for this column must be satisfied
            return rules.every((rule) => evaluateFilterRule(recordValue, rule, col));
        });
    });
}

/**
 * Sorts an array of records in-memory according to the current active sorter.
 * Supports custom column `sorter: (a, b) => number` functions as well as standard field comparisons.
 *
 * @param {Array<Object>} data
 * @param {Object} sorter Active sorter state { field, order }
 * @param {Array<Object>} columns
 * @returns {Array<Object>}
 */
export function sortRecordsLocally(data = [], sorter = {}, columns = []) {
    if (!sorter?.field || !sorter?.order) {
        return data;
    }

    const col = columns.find((c) => c.dataIndex === sorter.field);
    const orderMultiplier = sorter.order === "descend" ? -1 : 1;
    const sorted = [...data];

    // If a custom comparator function is defined on the column (e.g. keeping folders on top)
    if (typeof col?.sorter === "function") {
        return sorted.sort((a, b) => col.sorter(a, b) * orderMultiplier);
    }

    // Default comparator
    return sorted.sort((a, b) => {
        const valA = a[sorter.field];
        const valB = b[sorter.field];

        if (valA === valB) return 0;
        if (valA === null || valA === undefined) return 1;
        if (valB === null || valB === undefined) return -1;

        if (typeof valA === "number" && typeof valB === "number") {
            return (valA - valB) * orderMultiplier;
        }

        return String(valA).localeCompare(String(valB), undefined, { numeric: true }) * orderMultiplier;
    });
}
