import React from "react";
import { FILTER_OPERATORS } from "@CoreBundle/components/standard-table/filterCatalog";

/**
 * Resolves a raw filter value into its human-readable display label.
 * Checks col.filters and col.tagCatalog / col.tagColorMap / col.valueEnum before falling back to formatted string.
 *
 * @param {any} val Raw filter scalar value (e.g. "plan_to_watch")
 * @param {Object} [col] Column configuration object
 * @returns {string} Human-friendly label (e.g. "Plan To Watch")
 */
export function resolveHumanLabel(val, col) {
    if (!col) return String(val);

    // 1. Look up in col.tagCatalog or col.tagColorMap / col.valueEnum
    const catalog = col.tagCatalog || col.tagColorMap || col.valueEnum;
    if (catalog && catalog[val]) {
        const item = catalog[val];
        if (typeof item === "object" && (item.text || item.label)) {
            return String(item.text || item.label);
        }
    }

    // 2. Look up in col.filters (standard enum filter array: [{ text, value }])
    if (Array.isArray(col.filters)) {
        const found = col.filters.find((f) => String(f.value) === String(val));
        if (found) {
            const rawText = found.originalText ?? found.text;
            if (typeof rawText === "string" || typeof rawText === "number") {
                return String(rawText);
            }
            if (React.isValidElement(rawText)) {
                return rawText.props?.children || String(val);
            }
        }
    }

    // 3. Fallback: replace underscores with spaces and capitalize words
    if (typeof val === "string") {
        return val.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
    }

    return String(val);
}

/**
 * Returns a readable text summary of an active filter rule for badge/ribbon tags.
 *
 * @param {Object} rule Condition object containing { operator, value }
 * @param {Object} [col] The column configuration object
 * @returns {string} Human-friendly string description
 */
export function getRuleDescription(rule, col) {
    if (rule.operator === "empty") return "is empty";
    if (rule.operator === "notEmpty") return "is not empty";

    // Handle 'in' operator used by standard AntD checkbox dropdowns (Enums & Tags)
    if (rule.operator === "in") {
        const rawItems = Array.isArray(rule.value) ? rule.value : [rule.value];
        const labels = rawItems.map((v) => resolveHumanLabel(v, col));

        if (labels.length <= 2) {
            return labels.join(", ");
        }
        return `${labels.slice(0, 2).join(", ")} +${labels.length - 2}`;
    }

    const catalog = FILTER_OPERATORS[col?.filterType] || [];
    const opItem = catalog.find((o) => o.value === rule.operator);
    const opLabel = opItem ? opItem.label : rule.operator;

    if (Array.isArray(rule.value)) {
        const formattedRange = rule.value.map((v) => resolveHumanLabel(v, col));
        return `${opLabel} [${formattedRange.join(" - ")}]`;
    }

    return `${opLabel} "${resolveHumanLabel(rule.value, col)}"`;
}
