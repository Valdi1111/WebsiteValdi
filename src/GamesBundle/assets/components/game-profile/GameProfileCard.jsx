import { useGameProvider } from "@GamesBundle/components/GameProviderContext";
import {
    SettingOutlined,
    LinkOutlined,
    WarningOutlined,
    AreaChartOutlined,
    HistoryOutlined,
} from "@ant-design/icons";
import { Card, Avatar, Tag, Tooltip, Typography, Space } from "antd";
import { useNavigate } from "react-router";
import React, { useState } from "react";

const { Text } = Typography;

/**
 * Game profile summary card. Tags and actions are driven by the fields the backend exposes
 * for the profile (`"field" in profile`), so the same card serves every provider.
 */
export default function GameProfileCard({ profile, account, onOpenSettings }) {
    const navigate = useNavigate();
    const { basePath } = useGameProvider();
    const [imgError, setImgError] = useState(false);

    React.useEffect(() => {
        setImgError(false);
    }, [profile.icon_url]);

    const profilePath = `${basePath}/accounts/${account.id}/profiles/${profile.id}`;
    // The diary feature is supported only if the profile defines sync_diary
    const supportsDiary = "sync_diary" in profile;
    const isEnabled = (field) => field in profile && Boolean(profile[field]);

    const actions = [
        supportsDiary ? (
            <Tooltip title="View Resource Diary" key="diary">
                <AreaChartOutlined onClick={() => navigate(`${profilePath}/diary`)} />
            </Tooltip>
        ) : null,
        profile.supports_pulls ? (
            <Tooltip title="View Pulls History" key="pulls">
                <HistoryOutlined onClick={() => navigate(`${profilePath}/pulls`)} />
            </Tooltip>
        ) : null,
        <Tooltip title="Configure Settings" key="settings">
            <SettingOutlined onClick={() => onOpenSettings(profile.id)} />
        </Tooltip>,
        profile.hoyolab_url ? (
            <Tooltip title="Open on HoYoLAB" key="link">
                <a href={profile.hoyolab_url} target="_blank" rel="noreferrer">
                    <LinkOutlined />
                </a>
            </Tooltip>
        ) : null,
    ].filter(Boolean);

    return (
        <Card
            size="small"
            hoverable
            style={{ opacity: profile.active ? 1 : 0.65 }}
            actions={actions}
        >
            <Card.Meta
                avatar={
                    <Avatar
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
                        size={48}
                        style={{ filter: profile.active ? "none" : "grayscale(100%)" }}
                    >
                        {profile.game_name?.[0] ?? "G"}
                    </Avatar>
                }
                title={
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 4 }}>
                        <span style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                            {profile.nickname || `UID ${profile.game_uid}`}
                        </span>
                        <Space size={4}>
                            {profile.level ? <Tag color="blue">Lv.{profile.level}</Tag> : null}
                            <Tag color={profile.active ? "success" : "error"}>
                                {profile.active ? "Active" : "Suspended"}
                            </Tag>
                        </Space>
                    </div>
                }
                description={
                    <div>
                        <div><strong>{profile.game_name}</strong></div>
                        <Text type="secondary" style={{ fontSize: 12 }}>
                            UID: {profile.game_uid} | {profile.region_name || profile.region || profile.server_id}
                        </Text>
                        <div style={{ marginTop: 8, display: "flex", flexWrap: "wrap", gap: 4 }}>
                            {!profile.active ? (
                                <Tag color="default">All Automations Suspended</Tag>
                            ) : (
                                <>
                                    {(isEnabled("hoyolab_check_in") || isEnabled("skport_check_in")) && (
                                        <Tag color="green">Check-In</Tag>
                                    )}
                                    {isEnabled("code_redeem") && (
                                        account.can_redeem_codes ? (
                                            <Tag color="cyan">Codes</Tag>
                                        ) : (
                                            <Tooltip title="Code redemption is enabled in settings, but the account cookie lacks required redemption tokens.">
                                                <Tag color="warning" icon={<WarningOutlined />}>Codes (No Token)</Tag>
                                            </Tooltip>
                                        )
                                    )}
                                    {isEnabled("stamina_check") && (
                                        <Tag color="orange">Stamina</Tag>
                                    )}
                                    {isEnabled("realm_currency_check") && (
                                        <Tag color="purple">Teapot</Tag>
                                    )}
                                    {isEnabled("dailies_check") && (
                                        <Tag color="magenta">Dailies</Tag>
                                    )}
                                    {isEnabled("weeklies_check") && (
                                        <Tag color="volcano">Weeklies</Tag>
                                    )}
                                    {isEnabled("endgames_check") && (
                                        <Tag color="red">Endgames</Tag>
                                    )}
                                    {isEnabled("sync_diary") && (
                                        <Tag color="geekblue">Diary Sync</Tag>
                                    )}
                                    {isEnabled("sync_pulls") && (
                                        <Tag color="gold">Pulls Sync</Tag>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                }
            />
        </Card>
    );
}
