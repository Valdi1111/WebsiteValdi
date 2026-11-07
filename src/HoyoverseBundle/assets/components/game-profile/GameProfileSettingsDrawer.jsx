import { useBackendApi } from "@HoyoverseBundle/components/BackendApiContext";
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

export default function GameProfileSettingsDrawer({ open, accountId, profileId, accountCanRedeemCodes, onClose, onSaved }) {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [profile, setProfile] = useState(null);

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
                const checkAndSet = (key, fallback) => {
                    if (key in data) {
                        initialValues[key] = data[key] ?? fallback;
                    }
                };

                checkAndSet("active", true);
                checkAndSet("hoyolab_check_in", true);
                checkAndSet("hoyolab_missed_check_in", true);
                checkAndSet("code_redeem", true);
                checkAndSet("sync_diary", false);
                checkAndSet("stamina_check", false);
                checkAndSet("stamina_threshold", -1);
                checkAndSet("expeditions_check", true);
                checkAndSet("realm_currency_check", false);
                checkAndSet("realm_currency_threshold", -1);
                checkAndSet("shop_status_check", false);
                checkAndSet("mimo_check", false);
                checkAndSet("mimo_redeem", false);
                checkAndSet("mimo_redeem_draw", false);
                checkAndSet("mimo_lottery", false);
                checkAndSet("mimo_reserve_points", -1);
                checkAndSet("hilichurl_check", false);
                checkAndSet("hilichurl_redeem", false);
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
            .withLoadingMessage({
                key: "hoyoverse-profile-settings-saving",
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
                        <Avatar
                            size={64}
                            src={profile.icon_url}
                            shape="square"
                            style={{ filter: isProfileActive ? "none" : "grayscale(100%)" }}
                        >
                            {profile.game_name?.[0]}
                        </Avatar>
                        <div>
                            <h3 style={{ margin: 0 }}>
                                {profile.nickname} {profile.level ? <Tag color="blue">Lv. {profile.level}</Tag> : null}
                            </h3>
                            <div style={{ color: "#888" }}>{profile.game_name}</div>
                            <div style={{ fontSize: 12 }}>UID: <code>{profile.game_uid}</code></div>
                        </div>
                    </div>

                    <Descriptions column={2} size="small" bordered>
                        <Descriptions.Item label="Region">{profile.region_name || profile.region}</Descriptions.Item>
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
                                description="All background tasks (Check-ins, Redemptions, Resins, Web Events) are completely bypassed. You do not need to turn off individual switches below."
                            />
                        )}

                        {/* Hoyolab Check-In & Promo Codes */}
                        <Divider titlePlacement="start">
                            <strong>Hoyolab Check-In & Promo Codes</strong>
                        </Divider>

                        {supports("hoyolab_check_in") && (
                            <SettingRow
                                label="HoYoLAB Daily Check-In"
                                description="Automatically claim daily check-in rewards on HoYoLAB"
                            >
                                <Form.Item name="hoyolab_check_in" valuePropName="checked" noStyle>
                                    <Switch disabled={!isProfileActive} />
                                </Form.Item>
                            </SettingRow>
                        )}
                        {supports("hoyolab_missed_check_in") && (
                            <SettingRow
                                label="Missed Check-In Make-Up"
                                description="Automatically make up for missed check-ins using check-in cards"
                            >
                                <Form.Item name="hoyolab_missed_check_in" valuePropName="checked" noStyle>
                                    <Switch disabled={!isProfileActive} />
                                </Form.Item>
                            </SettingRow>
                        )}
                        {supports("code_redeem") && (
                            <SettingRow
                                label="Auto Promo Code Redemption"
                                description={
                                    !accountCanRedeemCodes ? (
                                        <span style={{ color: "#faad14" }}>
                                            Unavailable: Account cookie lacks redemption tokens (cookie_token_v2, account_mid_v2, account_id_v2). Re-add account cookie to enable.
                                        </span>
                                    ) : (
                                        "Automatically claim newly discovered promo codes"
                                    )
                                }
                            >
                                <Form.Item name="code_redeem" valuePropName="checked" noStyle>
                                    <Switch disabled={!isProfileActive || !accountCanRedeemCodes} />
                                </Form.Item>
                            </SettingRow>
                        )}

                        {/* Resource Diary Sync */}
                        {supports("sync_diary") && (
                            <>
                                <Divider titlePlacement="start">
                                    <strong>Resource Diary</strong>
                                </Divider>
                                <SettingRow
                                    label="Sync Diary Logs"
                                    description="Download and synchronize resource acquisition logs for charts and historical tracking"
                                >
                                    <Form.Item name="sync_diary" valuePropName="checked" noStyle>
                                        <Switch disabled={!isProfileActive} />
                                    </Form.Item>
                                </SettingRow>
                            </>
                        )}

                        {/* Stamina & Expeditions */}
                        {hasAny("stamina_check", "stamina_threshold", "expeditions_check") && (
                            <>
                                <Divider titlePlacement="start">
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
                                            <ThresholdInput min={-1} max={300} disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("expeditions_check") && (
                                    <SettingRow
                                        label="Expeditions Check"
                                        description="Check dispatch expeditions periodically and alert when completed"
                                    >
                                        <Form.Item name="expeditions_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
                        )}

                        {/* Realm Currency */}
                        {hasAny("realm_currency_check", "realm_currency_threshold") && (
                            <>
                                <Divider titlePlacement="start">
                                    <strong>Realm Currency (Serenita Pot)</strong>
                                </Divider>
                                {supports("realm_currency_check") && (
                                    <SettingRow
                                        label="Realm Currency Alert"
                                        description="Check teapot realm currency periodically and send alert when near limit"
                                    >
                                        <Form.Item name="realm_currency_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("realm_currency_threshold") && (
                                    <SettingRow
                                        label="Currency Threshold"
                                        description="Trigger alert when teapot coins reach this amount (Off = alert only at maximum capacity)"
                                    >
                                        <Form.Item name="realm_currency_threshold" noStyle>
                                            <ThresholdInput min={-1} step={100} width={120} disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
                        )}

                        {/* Daily Tasks & Weeklies Tasks */}
                        {hasAny("shop_status_check", "dailies_check", "weeklies_check") && (
                            <>
                                <Divider titlePlacement="start">
                                    <strong>Daily Tasks & Weeklies Tasks</strong>
                                </Divider>
                                {supports("shop_status_check") && (
                                    <SettingRow
                                        label="Shop Status Check"
                                        description="Check monthly reset status for Paimon's Bargains / in-game shop items"
                                    >
                                        <Form.Item name="shop_status_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("dailies_check") && (
                                    <SettingRow
                                        label="Dailies Check"
                                        description="Send alert when daily commission quests are not completed before daily reset"
                                    >
                                        <Form.Item name="dailies_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("weeklies_check") && (
                                    <SettingRow
                                        label="Weeklies Check"
                                        description="Send alert when weekly bosses and Battle Pass missions remain uncompleted"
                                    >
                                        <Form.Item name="weeklies_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
                        )}

                        {/* Mimo Web Events */}
                        {hasAny("mimo_check", "mimo_redeem", "mimo_redeem_draw", "mimo_lottery", "mimo_reserve_points") && (
                            <>
                                <Divider titlePlacement="start">
                                    <strong>HoYoLAB Mimo Events</strong>
                                </Divider>
                                {supports("mimo_check") && (
                                    <SettingRow
                                        label="Mimo Check"
                                        description="Perform daily check-in and missions on HoYoLAB Mimo events"
                                    >
                                        <Form.Item name="mimo_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("mimo_redeem") && (
                                    <SettingRow
                                        label="Mimo Redeem"
                                        description="Automatically redeem shop rewards using available Mimo points"
                                    >
                                        <Form.Item name="mimo_redeem" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("mimo_redeem_draw") && (
                                    <SettingRow
                                        label="Mimo Redeem Draw"
                                        description="Automatically participate in point prize draws"
                                    >
                                        <Form.Item name="mimo_redeem_draw" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("mimo_lottery") && (
                                    <SettingRow
                                        label="Mimo Lottery"
                                        description="Enter periodic Mimo lottery events"
                                    >
                                        <Form.Item name="mimo_lottery" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("mimo_reserve_points") && (
                                    <SettingRow
                                        label="Mimo Reserve Points"
                                        description="Minimum points to keep in reserve (Off = spend all available points)"
                                    >
                                        <Form.Item name="mimo_reserve_points" noStyle>
                                            <ThresholdInput min={-1} step={50} width={120} disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
                        )}

                        {/* Hilichurl Web Events */}
                        {hasAny("hilichurl_check", "hilichurl_redeem") && (
                            <>
                                <Divider titlePlacement="start">
                                    <strong>Hilichurl Treasure Events</strong>
                                </Divider>
                                {supports("hilichurl_check") && (
                                    <SettingRow
                                        label="Hilichurl Check"
                                        description="Complete daily tasks on Hilichurl's Treasure Corner event"
                                    >
                                        <Form.Item name="hilichurl_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("hilichurl_redeem") && (
                                    <SettingRow
                                        label="Hilichurl Redeem"
                                        description="Automatically redeem rewards in Hilichurl's Treasure Corner"
                                    >
                                        <Form.Item name="hilichurl_redeem" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
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
