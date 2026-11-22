import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { EditOutlined } from "@ant-design/icons";
import { Alert, App, Button, Form, InputNumber, Modal, Space, Typography } from "antd";
import React, { useEffect, useState } from "react";

const { Text } = Typography;

function pluralizeUnknown(count) {
    return count === 1 ? "1 unknown pull will be added" : `${count} unknown pulls will be added`;
}

/**
 * Declares the real pity of an S pull (operation A). The backend adds declared − recorded
 * unknown ("filler") pulls before the recorded ones; sending null removes the correction.
 *
 * @param {Object} props
 * @param {boolean} props.open
 * @param {() => void} props.onClose
 * @param {() => void} props.onSaved Called after the correction has been saved
 * @param {string|number} props.accountId
 * @param {string|number} props.gameProfileId
 * @param {Object|null} props.pull S row: id, name, origin, pity, recorded_pity, declared_pity, has_room
 * @param {number} props.sMaxPity
 * @param {Object} props.rarityConfig
 */
export default function DeclarePityModal({
    open,
    onClose,
    onSaved,
    accountId,
    gameProfileId,
    pull,
    sMaxPity = 90,
    rarityConfig,
}) {
    const api = useBackendApi();
    const { message } = App.useApp();
    const sLabel = rarityConfig?.s?.label || "S";

    const isManual = pull?.origin === "manual";
    const recordedPity = pull?.recorded_pity ?? pull?.pity ?? 1;
    const currentDeclared = pull?.declared_pity ?? null;

    const [value, setValue] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!open) {
            return;
        }
        const initial = currentDeclared ?? recordedPity;
        setValue(Math.min(Math.max(initial, recordedPity), sMaxPity));
        setSubmitting(false);
        setError(null);
    }, [open, currentDeclared, recordedPity, sMaxPity]);

    const valueValid = Number.isInteger(value) && value >= recordedPity && value <= sMaxPity;
    const unknownCount = valueValid ? value - recordedPity : null;
    // A manual S always keeps its declared pity: it cannot be cleared, only deleted
    const payload = valueValid ? (!isManual && value === recordedPity ? null : value) : undefined;
    const unchanged = payload === currentDeclared;
    const canSubmit = valueValid && !unchanged;

    const handleSubmit = () => {
        if (!canSubmit || !pull) {
            return;
        }
        setSubmitting(true);
        setError(null);

        api
            .gameProfiles()
            .declarePullPity(accountId, gameProfileId, pull.id, payload)
            .then(() => {
                message.success(payload === null ? "Pity correction removed" : "Pity correction saved");
                onSaved?.();
            })
            .catch((err) => {
                const data = err?.response?.data;
                setError(data?.detail || data?.message || err?.message || "Failed to save the pity.");
            })
            .finally(() => setSubmitting(false));
    };

    let preview = null;
    if (unknownCount !== null) {
        if (unknownCount > 0) {
            preview = `${pluralizeUnknown(unknownCount)} before the recorded ones`;
        } else if (isManual) {
            preview = "No unknown pulls will be added";
        } else {
            preview = currentDeclared !== null
                ? "No correction needed: the current correction will be removed"
                : "No correction needed";
        }
    }

    return (
        <Modal
            title={
                <Space>
                    <EditOutlined style={{ color: "#d48806" }} />
                    <span>Correct pity{pull?.name ? ` — ${pull.name}` : ""}</span>
                </Space>
            }
            open={open}
            onCancel={onClose}
            width={480}
            destroyOnHidden
            footer={[
                <Button key="cancel" onClick={onClose} disabled={submitting}>
                    Cancel
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    loading={submitting}
                    disabled={!canSubmit}
                    onClick={handleSubmit}
                >
                    Save
                </Button>,
            ]}
        >
            <Text type="secondary" style={{ display: "block", marginBottom: 16 }}>
                Enter the real pity of this {sLabel} pull (e.g. from the game's full history).
                {` ${recordedPity} ${recordedPity === 1 ? "pull is" : "pulls are"} recorded since the previous ${sLabel}; the missing ones are added as unknown pulls.`}
            </Text>

            <Form layout="vertical" component="div">
                <Form.Item
                    label="Real pity"
                    required
                    validateStatus={value !== null && !valueValid ? "error" : undefined}
                    help={
                        value !== null && !valueValid
                            ? `Enter a pity between ${recordedPity} and ${sMaxPity}`
                            : preview ?? `Between ${recordedPity} and ${sMaxPity}`
                    }
                >
                    <InputNumber
                        min={recordedPity}
                        max={sMaxPity}
                        precision={0}
                        value={value}
                        onChange={(next) => {
                            setValue(typeof next === "number" ? next : null);
                            setError(null);
                        }}
                        style={{ width: "100%" }}
                    />
                </Form.Item>
            </Form>

            {pull?.has_room === false && unknownCount !== null && unknownCount > 0 && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: error ? 12 : 0 }}
                    title="No time gap to place the unknown pulls"
                    description="The pulls before this one leave no room for estimated dates, so the server may reject this correction."
                />
            )}

            {error && (
                <Alert type="error" showIcon title="Could not save the pity" description={error} />
            )}
        </Modal>
    );
}
