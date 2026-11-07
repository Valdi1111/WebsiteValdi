import { SettingOutlined } from "@ant-design/icons";
import { Card, Avatar, Tag, Tooltip, Typography, Space } from "antd";
import React from "react";

const { Text } = Typography;

export default function GameProfileCard({ profile, accountId, onOpenSettings }) {
    const [imgError, setImgError] = React.useState(false);

    React.useEffect(() => {
        setImgError(false);
    }, [profile.icon_url]);

    const actions = [
        <Tooltip title="Configure Settings" key="settings">
            <SettingOutlined onClick={() => onOpenSettings(profile.id)} />
        </Tooltip>,
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
                        style={{
                            filter: profile.active ? "none" : "grayscale(100%)",
                        }}
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
                                    {"skport_check_in" in profile && profile.skport_check_in && (
                                        <Tag color="green">Check-In</Tag>
                                    )}
                                    {"stamina_check" in profile && profile.stamina_check && (
                                        <Tag color="orange">Stamina</Tag>
                                    )}
                                    {"dailies_check" in profile && profile.dailies_check && (
                                        <Tag color="purple">Dailies</Tag>
                                    )}
                                    {"weeklies_check" in profile && profile.weeklies_check && (
                                        <Tag color="geekblue">Weeklies</Tag>
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
