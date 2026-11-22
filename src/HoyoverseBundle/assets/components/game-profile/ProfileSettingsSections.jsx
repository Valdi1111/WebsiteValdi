import SettingRow from "@GamesBundle/components/SettingRow";
import ThresholdInput from "@GamesBundle/components/ThresholdInput";
import { Divider, Form, Switch } from "antd";
import React from "react";

/**
 * HoYoverse setting sections rendered inside the shared GameProfileSettingsDrawer form.
 */
export default function ProfileSettingsSections({ supports, hasAny, isProfileActive, profile, account }) {
    const accountCanRedeemCodes = Boolean(account?.can_redeem_codes);

    return <>
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
                            Automatic redemption unavailable: the account cookie lacks cookie_token_v2, account_mid_v2 or account_id_v2. New codes are notified for manual redemption; log in again with email and password to enable it.
                        </span>
                    ) : (
                        "Automatically claim newly discovered promo codes"
                    )
                }
            >
                <Form.Item name="code_redeem" valuePropName="checked" noStyle>
                    <Switch disabled={!isProfileActive} />
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

        {/* Pulls Tracker Sync (titled with the game's pull name, e.g. "Wishes") */}
        {supports("sync_pulls") && (
            <>
                <Divider titlePlacement="start">
                    <strong>{profile?.pull_label ?? "Gacha"} / Pulls Tracker</strong>
                </Divider>
                <SettingRow
                    label="Auto Pulls Synchronization"
                    description="Periodically download and synchronize new gacha pulls using SToken"
                >
                    <Form.Item name="sync_pulls" valuePropName="checked" noStyle>
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

        {/* Endgames */}
        {hasAny("endgames_check", "endgames_full_clear") && (
            <>
                <Divider titlePlacement="start">
                    <strong>Endgames</strong>
                </Divider>
                {supports("endgames_check") && (
                    <SettingRow
                        label="Endgames Reminder"
                        description="Send a daily alert in the last days of an endgame period (e.g. Shiyu Defense, Deadly Assault) for the modes not played yet"
                    >
                        <Form.Item name="endgames_check" valuePropName="checked" noStyle>
                            <Switch disabled={!isProfileActive} />
                        </Form.Item>
                    </SettingRow>
                )}
                {supports("endgames_full_clear") && (
                    <SettingRow
                        label="Remind Until Full Clear"
                        description="Also alert for the modes played but not at the maximum yet (e.g. stages below S rank, missing stars)"
                    >
                        <Form.Item name="endgames_full_clear" valuePropName="checked" noStyle>
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
    </>;
}
