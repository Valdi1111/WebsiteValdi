export const FILTER_OPERATORS = {
    text: [
        { label: "Contains", value: "like" },
        { label: "Does not contain", value: "notLike" },
        { label: "Starts with", value: "startsWith" },
        { label: "Ends with", value: "endsWith" },
        { label: "Equals", value: "exact" },
        { label: "Not equals", value: "neq" },
        { label: "Is empty", value: "empty" },
        { label: "Is not empty", value: "notEmpty" },
    ],
    number: [
        { label: "=", value: "eq" },
        { label: "≠", value: "neq" },
        { label: ">", value: "gt" },
        { label: "≥", value: "gte" },
        { label: "<", value: "lt" },
        { label: "≤", value: "lte" },
        { label: "Between", value: "between" },
    ],
    date: [
        { label: "Date Range", value: "dateRange" },
        { label: "Exact date", value: "dateExact" },
        { label: "From date", value: "fromDate" },
        { label: "To date", value: "toDate" },
    ],
};

/**
 * Returns available operators for a given data type, applying a whitelist if provided.
 *
 * @param {'text'|'number'|'date'} type
 * @param {string[]|null} [allowedOperators]
 * @returns {Array<{label: string, value: string}>}
 */
export function getAvailableOperators(type, allowedOperators = null) {
    const all = FILTER_OPERATORS[type] || [];
    if (!allowedOperators || !Array.isArray(allowedOperators)) {
        return all;
    }
    return all.filter((op) => allowedOperators.includes(op.value));
}