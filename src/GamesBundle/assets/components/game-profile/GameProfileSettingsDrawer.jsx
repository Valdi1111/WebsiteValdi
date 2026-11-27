import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { useGameProvider } from "@GamesBundle/components/GameProviderContext";
import GameProfileAvatar from "@GamesBundle/components/game-profile/GameProfileAvatar";
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
    Tag,
    Alert,
    Spin,
    ConfigProvider
} from "antd";

// Defaults shared by every provider; provider-specific ones come from provider.profileSettings.defaults
const COMMON_DEFAULTS = {
    active: true,
    notification_platforms: [],
};

/**
 * Profile details and settings drawer. Loading, header, profile status, notification platforms and
 * saving are shared; the provider renders its own setting sections via provider.profileSettings.Sections,
 * which receives { supports, hasAny, isProfileActive, profile, account }.
 */
export default function GameProfileSettingsDrawer({ open, account, profileId, onClose, onSaved }) {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [profile, setProfile] = useState(null);

    const api = useBackendApi();
    const provider = useGameProvider();
    const { defaults: providerDefaults = {}, Sections = null } = provider.profileSettings ?? {};
    const accountId = account?.id;

    // Watch active switch value; fallback to profile.active if undefined during mount
    const watchedActive = Form.useWatch("active", form);
    const isProfileActive = Boolean(watchedActive ?? profile?.active ?? true);

    // Field capability checkers: returns true if the property exists in the returned profile object
    const supports = useCallback((field) => profile !== null && field in profile, [profile]);
    const hasAny = useCallback((...fields) => fields.some(supports), [supports]);

    const handleAfterOpenChange = useCallback((opened) => {
        if (!opened) {
            setLoading(false);
            setProfile(null);
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

                // Populate only properties present in the profile object
                const initialValues = {};
                Object.entries({ ...COMMON_DEFAULTS, ...providerDefaults }).forEach(([key, fallback]) => {
                    if (key in data) {
                        initialValues[key] = data[key] ?? fallback;
                    }
                });

                form.setFieldsValue(initialValues);
            })
            .finally(() => {
                setLoading(false);
            });
    }, [accountId, profileId, api, form, providerDefaults]);

    const handleFinish = (values) => {
        setSaving(true);
        api
            .withLoadingMessage({
                key: `${provider.key}-profile-settings-saving`,
                loadingContent: "Saving settings...",
                successContent: "Settings saved successfully",
            })
            .gameProfiles()
            .updateSettings(accountId, profileId, values)
            .then(() => {
                onSaved?.();
                onClose();
            })
            .catch(() => {
                // Error already displayed by withLoadingMessage
            })
            .finally(() => {
                setSaving(false);
            });
    };

    return (
        <Drawer
            title="Profile Details & Settings"
            size={520}
            open={open}
            afterOpenChange={handleAfterOpenChange}
            onClose={onClose}
            closable={{ placement: 'end' }}
            destroyOnHidden
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
                        <GameProfileAvatar profile={profile} size={64} active={isProfileActive} />
                        <div>
                            <h3 style={{ margin: 0 }}>
                                {profile.nickname || `UID ${profile.game_uid}`} {profile.level ? <Tag color="blue">Lv. {profile.level}</Tag> : null}
                            </h3>
                            <div style={{ color: "#888" }}>{profile.game_name}</div>
                            <div style={{ fontSize: 12 }}>UID: <code>{profile.game_uid}</code></div>
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

                    <Divider titlePlacement="start">
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
                                description="All background tasks for this profile (check-ins, alerts, reminders and synchronizations) are completely bypassed. You do not need to turn off individual switches below."
                            />
                        )}

                        {/* Provider-specific setting sections */}
                        {Sections && (
                            <Sections
                                supports={supports}
                                hasAny={hasAny}
                                isProfileActive={isProfileActive}
                                profile={profile}
                                account={account}
                            />
                        )}

                        {/* Notification Platforms */}
                        {supports("notification_platforms") && (
                            <>
                                <Divider titlePlacement="start">
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
