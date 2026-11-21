import SettingRow from "@GamesBundle/components/SettingRow";
import ThresholdInput from "@GamesBundle/components/ThresholdInput";
import { Divider, Form, Switch } from "antd";
import React from "react";

/**
 * Gryphline setting sections rendered inside the shared GameProfileSettingsDrawer form.
 */
export default function ProfileSettingsSections({ supports, hasAny, isProfileActive, profile }) {
    return <>
        {/* SKPort Check-In & Promo Codes */}
        {hasAny("skport_check_in", "skport_missed_check_in") && (
            <>
                <Divider titlePlacement="start">
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
                            <ThresholdInput min={-1} max={360} step={10} width={120} disabled={!isProfileActive} />
                        </Form.Item>
                    </SettingRow>
                )}
            </>
        )}

        {/* Daily Tasks & Weeklies Tasks */}
        {hasAny("dailies_check", "weeklies_check") && (
            <>
                <Divider titlePlacement="start">
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

        {/* Pulls Tracker Sync (titled with the game's pull name, e.g. "Headhunts") */}
        {supports("sync_pulls") && (
            <>
                <Divider titlePlacement="start">
                    <strong>{profile?.pull_label ?? "Gacha"} / Pulls Tracker</strong>
                </Divider>
                <SettingRow
                    label="Auto Pulls Synchronization"
                    description="Periodically download and synchronize new headhunting pulls using account token"
                >
                    <Form.Item name="sync_pulls" valuePropName="checked" noStyle>
                        <Switch disabled={!isProfileActive} />
                    </Form.Item>
                </SettingRow>
            </>
        )}
    </>;
}
