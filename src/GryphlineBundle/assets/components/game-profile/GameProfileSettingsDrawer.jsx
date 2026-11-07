import { useBackendApi } from "@GryphlineBundle/components/BackendApiContext";
import SettingRow from "@CoreBundle/components/SettingRow";
import ThresholdInput from "@CoreBundle/components/ThresholdInput";
import React, { useState, useCallback } from "react";
import {
    Drawer,
    Form,
    Switch,
    Select,
    Button,
    Space,
    Divider,
    Descriptions,
    Avatar,
    Tag,
    Alert,
    Spin,
    ConfigProvider
} from "antd";

export default function GameProfileSettingsDrawer({ open, accountId, profileId, onClose, onSaved }) {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [profile, setProfile] = useState(null);
    const [imgError, setImgError] = useState(false);

    // Watch active switch value; fallback to profile.active if undefined during mount
    const watchedActive = Form.useWatch("active", form);
    const isProfileActive = Boolean(watchedActive ?? profile?.active ?? true);

    const api = useBackendApi();

    // Field capability checkers: returns true if the property exists in the returned profile object
    const supports = useCallback((field) => profile !== null && field in profile, [profile]);
    const hasAny = useCallback((...fields) => fields.some(supports), [supports]);

    const handleAfterOpenChange = useCallback((opened) => {
        if (!opened) {
            setLoading(false);
            setProfile(null);
            setImgError(false);
            form.resetFields();
            return;
        }

        if (!accountId || !profileId) return;

        setLoading(true);
        api
            .withErrorHandling()
            .gameProfiles()
            .getId(accountId, profileId)
            .then((res) => {
                const data = res.data;
                setProfile(data);
                setImgError(false);

                // Populate only properties present in the profile object
                const initialValues = {};
                const checkAndSet = (key, fallback) => {
                    if (key in data) {
                        initialValues[key] = data[key] ?? fallback;
                    }
                };

                checkAndSet("active", true);
                checkAndSet("skport_check_in", true);
                checkAndSet("skport_missed_check_in", true);
                checkAndSet("stamina_check", false);
                checkAndSet("stamina_threshold", -1);
                checkAndSet("dailies_check", false);
                checkAndSet("weeklies_check", false);
                checkAndSet("notification_platforms", []);

                form.setFieldsValue(initialValues);
            })
            .finally(() => {
                setLoading(false);
            });
    }, [accountId, profileId, api, form]);

    const handleFinish = (values) => {
        setSaving(true);
        api
            .withLoadingMessage({ content: "Saving settings..." })
            .withErrorHandling()
            .gameProfiles()
            .updateSettings(accountId, profileId, values)
            .then(() => {
                onSaved?.();
                onClose();
            })
            .finally(() => {
                setSaving(false);
            });
    };

    return (
        <Drawer
            title="Profile Details & Settings"
            open={open}
            afterOpenChange={handleAfterOpenChange}
            onClose={onClose}
            closable={{ placement: 'end' }}
            destroyOnHidden
            width={520}
            extra={
                <Space>
                    <Button
                        type="primary"
                        loading={saving}
                        disabled={loading || !profile}
                        onClick={() => form.submit()}
                    >
                        Save
                    </Button>
                </Space>
            }
        >
            {loading ? (
                <div style={{ textAlign: "center", padding: 50 }}>
                    <Spin size="large" />
                </div>
            ) : profile ? (
                <>
                    {/* Header info & avatar */}
                    <div style={{ display: "flex", alignItems: "center", gap: 16, marginBottom: 16 }}>
                        <Avatar
                            size={64}
                            src={
                                profile.icon_url && !imgError ? (
                                    <img
                                        src={profile.icon_url}
                                        alt={profile.game_name}
                                        referrerPolicy="no-referrer"
                                        onError={() => setImgError(true)}
                                    />
                                ) : undefined
                            }
                            shape="square"
                            style={{
                                filter: isProfileActive ? "none" : "grayscale(100%)",
                            }}
                        >
                            {profile.game_name?.[0] ?? "G"}
                        </Avatar>
                        <div>
                            <h3 style={{ margin: 0 }}>
                                {profile.nickname || `UID ${profile.game_uid}`} {profile.level ? <Tag color="blue">Lv. {profile.level}</Tag> : null}
                            </h3>
                            <div style={{ color: "#888" }}>
                                {profile.game_name}
                            </div>
                            <div style={{ fontSize: 12 }}>
                                UID: <code>{profile.game_uid}</code>
                            </div>
                        </div>
                    </div>

                    <Descriptions column={2} size="small" bordered>
                        <Descriptions.Item label="Region">{profile.region_name || profile.region || profile.server_id}</Descriptions.Item>
                        <Descriptions.Item label="Timezone">{profile.timezone || profile.parsed_timezone}</Descriptions.Item>
                        <Descriptions.Item label="Status">
                            <Tag color={isProfileActive ? "success" : "error"}>
                                {isProfileActive ? "Active" : "Suspended"}
                            </Tag>
                        </Descriptions.Item>
                        <Descriptions.Item label="Account">#{accountId}</Descriptions.Item>
                    </Descriptions>

                    <Divider orientation="left">
                        <strong>Global Status</strong>
                    </Divider>

                    <Form form={form} onFinish={handleFinish}>
                        {/* Profile Active switch with ConfigProvider wrapping Form.Item directly */}
                        {supports("active") && (
                            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 16, gap: 12 }}>
                                <div style={{ flex: 1 }}>
                                    <div><strong>Profile Active</strong></div>
                                    <div style={{ color: "#8c8c8c", fontSize: 12, lineHeight: 1.4, marginTop: 2 }}>
                                        When disabled, all automations and notifications for this profile are completely ignored.
                                    </div>
                                </div>
                                <div style={{ flexShrink: 0 }}>
                                    <ConfigProvider
                                        theme={{
                                            components: {
                                                Switch: {
                                                    trackMinWidth: 110,
                                                    innerMinMargin: 10,
                                                },
                                            },
                                        }}
                                    >
                                        <Form.Item name="active" valuePropName="checked" noStyle>
                                            <Switch checkedChildren="Active" unCheckedChildren="Suspended" />
                                        </Form.Item>
                                    </ConfigProvider>
                                </div>
                            </div>
                        )}

                        {!isProfileActive && (
                            <Alert
                                type="warning"
                                showIcon
                                style={{ marginBottom: 16 }}
                                title="Profile is Suspended"
                                description="All background tasks (Check-ins, Stamina, Reminders) are completely bypassed. You do not need to turn off individual switches below."
                            />
                        )}

                        {/* SKPort Check-In & Promo Codes */}
                        {hasAny("skport_check_in", "skport_missed_check_in") && (
                            <>
                                <Divider orientation="left">
                                    <strong>SKPort Check-In & Promo Codes</strong>
                                </Divider>
                                {supports("skport_check_in") && (
                                    <SettingRow
                                        label="SKPort Daily Check-In"
                                        description="Automatically claim daily check-in rewards on SKPort"
                                    >
                                        <Form.Item name="skport_check_in" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("skport_missed_check_in") && (
                                    <SettingRow
                                        label="Missed Check-In Make-Up"
                                        description="Automatically make up for missed check-ins using check-in cards"
                                    >
                                        <Form.Item name="skport_missed_check_in" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
                        )}

                        {/* Stamina & Expeditions */}
                        {hasAny("stamina_check", "stamina_threshold") && (
                            <>
                                <Divider orientation="left">
                                    <strong>Stamina & Expeditions</strong>
                                </Divider>
                                {supports("stamina_check") && (
                                    <SettingRow
                                        label="Stamina Alert"
                                        description="Check stamina periodically and send alert when approaching threshold"
                                    >
                                        <Form.Item name="stamina_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("stamina_threshold") && (
                                    <SettingRow
                                        label="Stamina Threshold"
                                        description="Trigger alert when stamina reaches this amount (Off = alert only at maximum capacity)"
                                    >
                                        <Form.Item name="stamina_threshold" noStyle>
                                            <ThresholdInput min={-1} max={360} step={10} width={120} disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
                        )}

                        {/* Daily Tasks & Weeklies Tasks */}
                        {hasAny("dailies_check", "weeklies_check") && (
                            <>
                                <Divider orientation="left">
                                    <strong>Daily Tasks & Weeklies Tasks</strong>
                                </Divider>
                                {supports("dailies_check") && (
                                    <SettingRow
                                        label="Dailies Check"
                                        description="Send alert when daily tasks are not completed before daily reset"
                                    >
                                        <Form.Item name="dailies_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("weeklies_check") && (
                                    <SettingRow
                                        label="Weeklies Check"
                                        description="Send alert when weekly tasks are not completed before weekly reset"
                                    >
                                        <Form.Item name="weeklies_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
                        )}

                        {/* Notification Platforms */}
                        {supports("notification_platforms") && (
                            <>
                                <Divider orientation="left">
                                    <strong>Notification Platforms</strong>
                                </Divider>
                                <div style={{ marginBottom: 16 }}>
                                    <Form.Item name="notification_platforms" noStyle>
                                        <Select
                                            mode="multiple"
                                            placeholder="Select notification platforms"
                                            style={{ width: "100%" }}
                                            disabled={!isProfileActive}
                                            options={[
                                                { label: "Telegram", value: "telegram" },
                                                { label: "Discord", value: "discord" },
                                            ]}
                                        />
                                    </Form.Item>
                                </div>
                            </>
                        )}
                    </Form>
                </>
            ) : null}
        </Drawer>
    );
}
