import React from "react";
import { Card, Avatar, Tag, Tooltip, Typography, Space } from "antd";
import { SettingOutlined, LinkOutlined } from "@ant-design/icons";

const { Text } = Typography;

export default function HoyoverseGameProfileCard({ profile, onOpenSettings }) {
    const actions = [
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
                        src={profile.icon_url}
                        shape="square"
                        size={48}
                        style={{ filter: profile.active ? "none" : "grayscale(100%)" }}
                    >
                        {profile.game_name?.[0]}
                    </Avatar>
                }
                title={
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 4 }}>
                        <span style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                            {profile.nickname}
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
                            UID: {profile.game_uid} | {profile.parsed_region || profile.region}
                        </Text>
                        <div style={{ marginTop: 8, display: "flex", flexWrap: "wrap", gap: 4 }}>
                            {!profile.active ? (
                                <Tag color="default">All Automations Suspended</Tag>
                            ) : (
                                <>
                                    {"hoyolab_check_in" in profile && profile.hoyolab_check_in && (
                                        <Tag color="green">Check-In</Tag>
                                    )}
                                    {"code_redeem" in profile && profile.code_redeem && (
                                        <Tag color="cyan">Codes</Tag>
                                    )}
                                    {"stamina_check" in profile && profile.stamina_check && (
                                        <Tag color="orange">Stamina</Tag>
                                    )}
                                    {"realm_currency_check" in profile && profile.realm_currency_check && (
                                        <Tag color="purple">Teapot</Tag>
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