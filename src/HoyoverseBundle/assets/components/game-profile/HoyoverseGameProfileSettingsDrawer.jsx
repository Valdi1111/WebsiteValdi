import { useBackendApi } from "@HoyoverseBundle/components/BackendApiContext";
import React, { useState, useCallback } from "react";
import {
    Drawer,
    Form,
    Switch,
    InputNumber,
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

/**
 * Reusable layout row ensuring labels and input controls stay side-by-side
 * on both desktop and mobile screens without breaking onto multiple lines.
 */
function SettingRow({ label, description, children }) {
    return (
        <div
            style={{
                display: "flex",
                justifyContent: "space-between",
                alignItems: "center",
                marginBottom: 16,
                gap: 12,
            }}
        >
            <div style={{ flex: 1 }}>
                <div>{label}</div>
                {description && (
                    <div style={{ color: "#8c8c8c", fontSize: 12, lineHeight: 1.3, marginTop: 2 }}>
                        {description}
                    </div>
                )}
            </div>
            <div style={{ flexShrink: 0 }}>
                {children}
            </div>
        </div>
    );
}

/**
 * Number input with explicit visual indicator when threshold is disabled (-1).
 * Displays "Off" directly in the input when value is <= -1, with a "Turn off" button stacked underneath.
 */
function ThresholdInput({ value, onChange, min = -1, max, step = 1, disabled, width = 110 }) {
    const isOff = value === undefined || value === null || value <= -1;

    return (
        <div style={{ display: "flex", flexDirection: "column", alignItems: "flex-end" }}>
            <InputNumber
                min={min}
                max={max}
                step={step}
                value={value}
                onChange={onChange}
                disabled={disabled}
                style={{ width }}
                formatter={(val) => {
                    if (val === "" || val === undefined || val === null) return "";
                    return Number(val) <= -1 ? "Off" : String(val);
                }}
                parser={(displayValue) => {
                    if (!displayValue || displayValue.trim().toLowerCase() === "off") {
                        return -1;
                    }
                    const parsed = parseInt(displayValue.replace(/[^\d-]/g, ""), 10);
                    return isNaN(parsed) ? -1 : parsed;
                }}
            />
            {!disabled && !isOff && (
                <Button
                    size="small"
                    type="link"
                    danger
                    onClick={() => onChange?.(-1)}
                    style={{ fontSize: 11, padding: 0, height: 18, marginTop: 2 }}
                >
                    Turn off
                </Button>
            )}
        </div>
    );
}

export default function HoyoverseGameProfileSettingsDrawer({ open, accountId, profileId, accountCanRedeemCodes, onClose, onSaved }) {
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
                checkAndSet("expedition_check", true);
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
                checkAndSet("dailies_check", true);
                checkAndSet("weeklies_check", true);
                checkAndSet("notification_platforms", []);

                form.setFieldsValue(initialValues);
            })
            .finally(() => {
                setLoading(false);
            });
    }, [accountId, profileId, api, form]);

    const handleFinish = (values) => {
        setSaving(true);
        const payload = {};
        for (const [key, val] of Object.entries(values)) {
            if (supports(key)) {
                payload[key] = val;
            }
        }

        api
            .withErrorHandling()
            .gameProfiles()
            .updateSettings(accountId, profileId, payload)
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
            placement="right"
            size={540}
            open={open}
            onClose={onClose}
            afterOpenChange={handleAfterOpenChange}
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
                            <div style={{ color: "#888" }}>{profile.game_name} ({profile.region_name || profile.region})</div>
                            <div style={{ fontSize: 12 }}>UID: <code>{profile.game_uid}</code></div>
                        </div>
                    </div>

                    <Descriptions column={2} size="small" bordered>
                        <Descriptions.Item label="Region">{profile.parsed_region}</Descriptions.Item>
                        <Descriptions.Item label="Timezone">{profile.parsed_timezone}</Descriptions.Item>
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
                                description="All background tasks (Check-ins, Redemptions, Resins, Web Events) are completely bypassed. You do not need to turn off individual switches below."
                            />
                        )}

                        {/* Daily Check-In & Promo Codes */}
                        <Divider orientation="left">
                            <strong>Daily Check-In & Promo Codes</strong>
                        </Divider>

                        {supports("hoyolab_check_in") && (
                            <SettingRow label="HoYoLAB Daily Check-In">
                                <Form.Item name="hoyolab_check_in" valuePropName="checked" noStyle>
                                    <Switch disabled={!isProfileActive} />
                                </Form.Item>
                            </SettingRow>
                        )}
                        {supports("hoyolab_missed_check_in") && (
                            <SettingRow label="Missed Check-In Make-Up">
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
                                <Divider orientation="left">
                                    <strong>Resource Diary & History</strong>
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
                        {hasAny("stamina_check", "stamina_threshold", "expedition_check") && (
                            <>
                                <Divider orientation="left">
                                    <strong>Stamina & Expeditions</strong>
                                </Divider>
                                {supports("stamina_check") && (
                                    <SettingRow label="Stamina Alert">
                                        <Form.Item name="stamina_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("stamina_threshold") && (
                                    <SettingRow label="Stamina Threshold" description="Trigger alert when stamina reaches this amount">
                                        <Form.Item name="stamina_threshold" noStyle>
                                            <ThresholdInput min={-1} max={300} disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("expedition_check") && (
                                    <SettingRow label="Expeditions Check">
                                        <Form.Item name="expedition_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
                        )}

                        {/* Realm Currency */}
                        {hasAny("realm_currency_check", "realm_currency_threshold") && (
                            <>
                                <Divider orientation="left">
                                    <strong>Realm Currency (Serenitea Pot)</strong>
                                </Divider>
                                {supports("realm_currency_check") && (
                                    <SettingRow label="Realm Currency Alert">
                                        <Form.Item name="realm_currency_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("realm_currency_threshold") && (
                                    <SettingRow label="Currency Threshold" description="Trigger alert when teapot coins reach this amount">
                                        <Form.Item name="realm_currency_threshold" noStyle>
                                            <ThresholdInput min={-1} step={100} width={120} disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                            </>
                        )}

                        {/* Daily / Weekly / Shop Tasks */}
                        {hasAny("shop_status_check", "dailies_check", "weeklies_check") && (
                            <>
                                <Divider orientation="left">
                                    <strong>Daily Tasks & Shop</strong>
                                </Divider>
                                {supports("shop_status_check") && (
                                    <SettingRow label="Shop Status Check">
                                        <Form.Item name="shop_status_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("dailies_check") && (
                                    <SettingRow label="Dailies Check">
                                        <Form.Item name="dailies_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("weeklies_check") && (
                                    <SettingRow label="Weeklies Check">
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
                                <Divider orientation="left">
                                    <strong>HoYoLAB Mimo Events</strong>
                                </Divider>
                                {supports("mimo_check") && (
                                    <SettingRow label="Mimo Check">
                                        <Form.Item name="mimo_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("mimo_redeem") && (
                                    <SettingRow label="Mimo Redeem">
                                        <Form.Item name="mimo_redeem" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("mimo_redeem_draw") && (
                                    <SettingRow label="Mimo Redeem Draw">
                                        <Form.Item name="mimo_redeem_draw" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("mimo_lottery") && (
                                    <SettingRow label="Mimo Lottery">
                                        <Form.Item name="mimo_lottery" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("mimo_reserve_points") && (
                                    <SettingRow label="Mimo Reserve Points" description="Points to keep in reserve (Off = spend all)">
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
                                <Divider orientation="left">
                                    <strong>Hilichurl Treasure Events</strong>
                                </Divider>
                                {supports("hilichurl_check") && (
                                    <SettingRow label="Hilichurl Check">
                                        <Form.Item name="hilichurl_check" valuePropName="checked" noStyle>
                                            <Switch disabled={!isProfileActive} />
                                        </Form.Item>
                                    </SettingRow>
                                )}
                                {supports("hilichurl_redeem") && (
                                    <SettingRow label="Hilichurl Redeem">
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