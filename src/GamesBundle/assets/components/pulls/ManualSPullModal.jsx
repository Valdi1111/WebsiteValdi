import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import {
    buildDisabledDate,
    buildDisabledTime,
    formatPullDate,
    getAllowedDateRange,
    isDateInRange,
    parsePullDate,
    PULL_DATE_FORMAT,
} from "@GamesBundle/components/pulls/manualPullDates";
import { PlusOutlined } from "@ant-design/icons";
import {
    Alert,
    App,
    AutoComplete,
    Button,
    DatePicker,
    Form,
    InputNumber,
    Modal,
    Select,
    Space,
    Spin,
    Typography,
} from "antd";
import React, { useEffect, useMemo, useState } from "react";

const { Text } = Typography;

const MAX_VISIBLE_SUGGESTIONS = 50;

const EMPTY_SUGGESTIONS = { items: [], allowFreeText: false, itemTypes: [] };

function normalizeName(name) {
    return (name || "").trim().toLowerCase();
}

function getInitials(name) {
    if (!name) return "?";
    const words = name.trim().split(/\s+/);
    if (words.length === 1) {
        return words[0].substring(0, 2).toUpperCase();
    }
    return (words[0][0] + words[1][0]).toUpperCase();
}

function SuggestionLabel({ item }) {
    const [imgError, setImgError] = useState(false);
    const showImage = Boolean(item.icon_url) && !imgError;
    const typeLabel = item.item_type_label ?? item.item_type;

    return (
        <div style={{ display: "flex", alignItems: "center", gap: 8, minWidth: 0 }}>
            <div
                style={{
                    width: 24,
                    height: 24,
                    borderRadius: "50%",
                    overflow: "hidden",
                    flexShrink: 0,
                    display: "inline-flex",
                    alignItems: "center",
                    justifyContent: "center",
                    background: "rgba(0, 0, 0, 0.06)",
                    fontSize: 10,
                    fontWeight: 700,
                }}
            >
                {showImage ? (
                    <img
                        src={item.icon_url}
                        alt={item.name}
                        loading="lazy"
                        onError={() => setImgError(true)}
                        style={{ width: "100%", height: "100%", objectFit: "cover", display: "block" }}
                    />
                ) : (
                    getInitials(item.name)
                )}
            </div>
            <span style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                {item.name}
            </span>
            {typeLabel && (
                <Text type="secondary" style={{ fontSize: 12, flexShrink: 0 }}>
                    ({typeLabel})
                </Text>
            )}
        </div>
    );
}

function pluralizeUnknown(count) {
    return count === 1 ? "1 unknown pull" : `${count} unknown pulls`;
}

/**
 * Adds a manual S pull (operation B) before the anchor S, or in the open window of the banner
 * when anchor is null. The backend creates pity − 1 unknown ("filler") pulls before it.
 *
 * @param {Object} props
 * @param {boolean} props.open
 * @param {() => void} props.onClose
 * @param {() => void} props.onSaved Called after the pull has been created
 * @param {string|number} props.accountId
 * @param {string|number} props.gameProfileId
 * @param {string} props.bannerKey
 * @param {Object|null} props.anchor S row the new pull goes before, null for the open window
 * @param {{insert_after: ?string, insert_before: ?string, suggested_at: ?string, has_room: ?boolean}|null} props.window
 * @param {number} props.sMaxPity
 * @param {Object} props.rarityConfig
 */
export default function ManualSPullModal({
    open,
    onClose,
    onSaved,
    accountId,
    gameProfileId,
    bannerKey,
    anchor,
    window: insertWindow,
    sMaxPity = 90,
    rarityConfig,
}) {
    const api = useBackendApi();
    const { message } = App.useApp();
    const sLabel = rarityConfig?.s?.label || "S";

    const [suggestions, setSuggestions] = useState(EMPTY_SUGGESTIONS);
    const [loadingSuggestions, setLoadingSuggestions] = useState(false);
    const [suggestionsError, setSuggestionsError] = useState(null);

    const [name, setName] = useState("");
    const [pickedIndex, setPickedIndex] = useState(null);
    const [itemType, setItemType] = useState(null);
    const [pulledAt, setPulledAt] = useState(null);
    const [pity, setPity] = useState(null);

    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    // Set after a possible_duplicate answer: the next submit resends with allow_duplicate
    const [duplicateDetail, setDuplicateDetail] = useState(null);
    // Range errors are shown only after a submit attempt, not while the user is typing
    const [submitAttempted, setSubmitAttempted] = useState(false);

    const insertAfter = insertWindow?.insert_after ?? null;
    const insertBefore = insertWindow?.insert_before ?? null;
    const suggestedAt = insertWindow?.suggested_at ?? null;

    // Reset the form and (re)load the suggestions every time the modal opens
    useEffect(() => {
        if (!open) {
            return;
        }

        setName("");
        setPickedIndex(null);
        setItemType(null);
        setPulledAt(parsePullDate(suggestedAt));
        setPity(null);
        setSubmitting(false);
        setError(null);
        setDuplicateDetail(null);
        setSubmitAttempted(false);
        setSuggestions(EMPTY_SUGGESTIONS);
        setSuggestionsError(null);
        setLoadingSuggestions(true);

        // Ignore late responses once the modal is closed or reopened for another banner
        let cancelled = false;
        api
            .gameProfiles()
            .getManualPullSuggestions(accountId, gameProfileId, bannerKey)
            .then((res) => {
                if (cancelled) return;
                const data = res?.data || {};
                setSuggestions({
                    items: Array.isArray(data.items) ? data.items : [],
                    allowFreeText: Boolean(data.allow_free_text),
                    itemTypes: Array.isArray(data.item_types) ? data.item_types : [],
                });
            })
            .catch((err) => {
                if (cancelled) return;
                setSuggestionsError(
                    err?.response?.data?.detail || err?.message || "Failed to load the suggestions."
                );
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingSuggestions(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open, api, accountId, gameProfileId, bannerKey, suggestedAt]);

    // The picker also hides the future ("now" captured when the modal opens)
    const pickerRange = useMemo(
        () => getAllowedDateRange(insertAfter, insertBefore),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [open, insertAfter, insertBefore]
    );
    // Submit checks only the insertion bounds: pull dates are game time, not browser time,
    // so the "not in the future" check is left to the server (date_in_future)
    const submitRange = useMemo(
        () => getAllowedDateRange(insertAfter, insertBefore, null),
        [insertAfter, insertBefore]
    );
    const disabledDate = useMemo(() => buildDisabledDate(pickerRange), [pickerRange]);
    const disabledTime = useMemo(() => buildDisabledTime(pickerRange), [pickerRange]);

    const { items, allowFreeText, itemTypes } = suggestions;

    // Clears the server answer whenever the user changes something
    const touch = () => {
        setError(null);
        setDuplicateDetail(null);
    };

    /**
     * Resolves the item to send from the current input. With free text, an exact (case-insensitive)
     * name match against the suggestions wins, so a pick-then-edit never sends a stale item_id.
     */
    const resolvedItem = useMemo(() => {
        if (!allowFreeText) {
            const item = pickedIndex !== null ? items[pickedIndex] : null;
            return item ? { item, freeText: false } : null;
        }

        const text = name.trim();
        if (!text) {
            return null;
        }
        const normalized = normalizeName(text);
        const picked = pickedIndex !== null ? items[pickedIndex] : null;
        const match = picked && normalizeName(picked.name) === normalized
            ? picked
            : items.find((item) => normalizeName(item.name) === normalized);
        if (match) {
            return { item: match, freeText: false };
        }

        return { item: { name: text, item_id: null, item_type: null }, freeText: true };
    }, [allowFreeText, items, pickedIndex, name]);

    const needsItemType = Boolean(resolvedItem?.freeText) && itemTypes.length > 0;

    const autoCompleteOptions = useMemo(() => {
        if (!allowFreeText) return [];
        const query = normalizeName(name);
        const options = [];
        for (let index = 0; index < items.length && options.length < MAX_VISIBLE_SUGGESTIONS; index++) {
            const item = items[index];
            const haystack = `${item.name} ${item.item_type_label ?? item.item_type ?? ""}`.toLowerCase();
            if (!query || haystack.includes(query)) {
                options.push({ key: String(index), value: item.name, label: item.name, item, index });
            }
        }
        return options;
    }, [allowFreeText, items, name]);

    const selectOptions = useMemo(
        () => (allowFreeText ? [] : items.map((item, index) => ({
            value: index,
            label: item.name,
            item,
            searchText: `${item.name} ${item.item_type_label ?? item.item_type ?? ""}`.toLowerCase(),
        }))),
        [allowFreeText, items]
    );

    const pityValid = Number.isInteger(pity) && pity >= 1 && pity <= sMaxPity;
    const dateValid = isDateInRange(pulledAt, submitRange);
    const itemValid = Boolean(resolvedItem) && (!needsItemType || Boolean(itemType));
    const canSubmit = itemValid && dateValid && pityValid && !loadingSuggestions;

    const rangeText = `after ${insertAfter ?? "the start of the history"} and before ${insertBefore ?? "now"}`;

    const submit = (allowDuplicate) => {
        setSubmitAttempted(true);
        if (!canSubmit) {
            return;
        }

        const { item, freeText } = resolvedItem;
        setSubmitting(true);
        setError(null);

        api
            .gameProfiles()
            .createManualPull(accountId, gameProfileId, {
                banner: bannerKey,
                anchor_id: anchor?.id ?? null,
                name: item.name,
                item_id: item.item_id ?? null,
                item_type: freeText ? (itemType ?? null) : (item.item_type ?? null),
                pulled_at: formatPullDate(pulledAt),
                pity,
                allow_duplicate: allowDuplicate,
            })
            .then(() => {
                message.success(`${sLabel} pull added`);
                onSaved?.();
            })
            .catch((err) => {
                const data = err?.response?.data;
                if (data?.code === "possible_duplicate" && !allowDuplicate) {
                    setDuplicateDetail(data.detail || "");
                    return;
                }
                setDuplicateDetail(null);
                setError(data?.detail || data?.message || err?.message || "Failed to add the pull.");
            })
            .finally(() => setSubmitting(false));
    };

    const unknownCount = pityValid ? pity - 1 : null;
    const askDuplicate = duplicateDetail !== null;

    return (
        <Modal
            title={
                <Space>
                    <PlusOutlined style={{ color: "#d48806" }} />
                    <span>{anchor ? `Add ${sLabel} before ${anchor.name}` : `Add missing ${sLabel}`}</span>
                </Space>
            }
            open={open}
            onCancel={onClose}
            width={520}
            destroyOnHidden
            footer={[
                <Button key="cancel" onClick={onClose} disabled={submitting}>
                    Cancel
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    loading={submitting}
                    disabled={loadingSuggestions || Boolean(suggestionsError)}
                    onClick={() => submit(askDuplicate)}
                >
                    {askDuplicate ? "Add anyway" : "Add"}
                </Button>,
            ]}
        >
            <Text type="secondary" style={{ display: "block", marginBottom: 16 }}>
                {anchor
                    ? `The ${sLabel} pull is added before ${anchor.name} (${anchor.pulled_at}), with unknown pulls before it to match its pity.`
                    : `The ${sLabel} pull is added to the current pity window, with unknown pulls before it to match its pity.`}
            </Text>

            {suggestionsError && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 16 }}
                    title="Could not load the suggestions"
                    description={suggestionsError}
                />
            )}

            <Form layout="vertical" component="div">
                <Form.Item
                    label="Item"
                    required
                    validateStatus={submitAttempted && !resolvedItem ? "error" : undefined}
                    help={
                        submitAttempted && !resolvedItem
                            ? "Pick an item"
                            : resolvedItem?.freeText
                                ? "Not in the suggestions: saved as typed, without an icon"
                                : undefined
                    }
                >
                    {allowFreeText ? (
                        <AutoComplete
                            value={name}
                            options={autoCompleteOptions}
                            optionRender={(option) => <SuggestionLabel item={option.data.item} />}
                            onChange={(value) => {
                                setName(value ?? "");
                                touch();
                            }}
                            onSelect={(value, option) => {
                                setName(option?.item?.name ?? value);
                                setPickedIndex(option?.index ?? null);
                                touch();
                            }}
                            placeholder="Search or type a name"
                            notFoundContent={loadingSuggestions ? <Spin size="small" /> : null}
                            allowClear
                            style={{ width: "100%" }}
                        />
                    ) : (
                        <Select
                            value={pickedIndex}
                            options={selectOptions}
                            optionRender={(option) => <SuggestionLabel item={option.data.item} />}
                            showSearch={{
                                filterOption: (input, option) =>
                                    (option?.searchText ?? "").includes(input.trim().toLowerCase()),
                            }}
                            onChange={(value) => {
                                setPickedIndex(value ?? null);
                                touch();
                            }}
                            loading={loadingSuggestions}
                            placeholder="Search an item"
                            notFoundContent={loadingSuggestions ? <Spin size="small" /> : undefined}
                            allowClear
                            style={{ width: "100%" }}
                        />
                    )}
                </Form.Item>

                {needsItemType && (
                    <Form.Item
                        label="Item type"
                        required
                        validateStatus={submitAttempted && !itemType ? "error" : undefined}
                        help={
                            submitAttempted && !itemType
                                ? "Pick the item type"
                                : "Not in the list: pick its type"
                        }
                    >
                        <Select
                            value={itemType}
                            options={itemTypes.map((type) => ({ value: type.value, label: type.label }))}
                            onChange={(value) => {
                                setItemType(value ?? null);
                                touch();
                            }}
                            placeholder="Item type"
                            style={{ width: "100%" }}
                        />
                    </Form.Item>
                )}

                <Form.Item
                    label="Date"
                    required
                    validateStatus={submitAttempted && !dateValid ? "error" : undefined}
                    help={
                        submitAttempted && !dateValid
                            ? `Pick a date ${rangeText}`
                            : `Allowed: ${rangeText}`
                    }
                >
                    <DatePicker
                        showTime
                        format={PULL_DATE_FORMAT}
                        value={pulledAt}
                        disabledDate={disabledDate}
                        disabledTime={disabledTime}
                        onChange={(value) => {
                            setPulledAt(value ?? null);
                            touch();
                        }}
                        style={{ width: "100%" }}
                    />
                </Form.Item>

                <Form.Item
                    label="Pity"
                    required
                    validateStatus={submitAttempted && !pityValid ? "error" : undefined}
                    help={
                        submitAttempted && !pityValid
                            ? `Enter a pity between 1 and ${sMaxPity}`
                            : unknownCount === null
                                ? `Between 1 and ${sMaxPity}`
                                : unknownCount === 0
                                    ? "No unknown pulls will be added"
                                    : `${pluralizeUnknown(unknownCount)} will be added`
                    }
                >
                    <InputNumber
                        min={1}
                        max={sMaxPity}
                        precision={0}
                        value={pity}
                        onChange={(value) => {
                            setPity(typeof value === "number" ? value : null);
                            touch();
                        }}
                        placeholder={`1–${sMaxPity}`}
                        style={{ width: "100%" }}
                    />
                </Form.Item>
            </Form>

            {askDuplicate && (
                <Alert
                    type="warning"
                    showIcon
                    title="A pull of the same item already exists within 24h. Add it anyway?"
                    description={duplicateDetail || undefined}
                />
            )}

            {error && (
                <Alert type="error" showIcon title="Could not add the pull" description={error} />
            )}
        </Modal>
    );
}
