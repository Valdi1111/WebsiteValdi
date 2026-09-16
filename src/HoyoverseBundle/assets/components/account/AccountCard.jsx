import { UserOutlined, SyncOutlined, DeleteOutlined, GiftOutlined, WarningOutlined, ReloadOutlined } from "@ant-design/icons";
import { Card, Space, Button, Popconfirm, Typography, Row, Col, Empty, Tag, Tooltip } from "antd";
import GameProfileCard from "@HoyoverseBundle/components/game-profile/GameProfileCard";
import React from "react";

const { Text } = Typography;

export default function AccountCard({
                                        account,
                                        isSyncing,
                                        onSync,
                                        onDelete,
                                        onOpenProfileSettings
                                    }) {
    const hasProfiles = account.game_profiles && account.game_profiles.length > 0;
    const canRedeemCodes = Boolean(account.can_redeem_codes);
    const canAutoRenew = Boolean(account.can_auto_renew);

    return (
        <Card
            styles={{
                header: {
                    height: "auto",
                    padding: "12px 16px",
                },
            }}
            title={
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", flexWrap: "wrap", gap: "10px 16px", width: "100%" }}>
                    {/* Left section: Title, Added Date, and Status Badges */}
                    <div style={{ display: "flex", flexDirection: "column", gap: 6, flex: "1 1 200px" }}>
                        <Space align="center" size="small" wrap>
                            <UserOutlined />
                            <span>Account #{account.id}</span>
                            <Text type="secondary" style={{ fontSize: 12 }}>
                                (Added: {account.added_at ? new Date(account.added_at).toLocaleDateString() : "-"})
                            </Text>
                        </Space>

                        {/* Status badges wrapped cleanly to avoid collisions on smaller screens */}
                        <Space wrap size={[6, 6]}>
                            {canAutoRenew ? (
                                <Tag color="green" icon={<ReloadOutlined />} style={{ margin: 0 }}>
                                    Auto-Renew Ready
                                </Tag>
                            ) : (
                                <Tooltip title="Account cookie is missing 'stoken'. Cookies cannot be automatically renewed and will expire.">
                                    <Tag color="warning" icon={<WarningOutlined />} style={{ margin: 0 }}>
                                        Auto-Renew Unavailable
                                    </Tag>
                                </Tooltip>
                            )}

                            {canRedeemCodes ? (
                                <Tag color="cyan" icon={<GiftOutlined />} style={{ margin: 0 }}>
                                    Code Redemption Ready
                                </Tag>
                            ) : (
                                <Tooltip title="Cookie is missing redemption tokens (cookie_token_v2, account_mid_v2, account_id_v2). Promo codes cannot be redeemed automatically.">
                                    <Tag color="warning" icon={<WarningOutlined />} style={{ margin: 0 }}>
                                        Code Redemption Unavailable
                                    </Tag>
                                </Tooltip>
                            )}
                        </Space>
                    </div>

                    {/* Right section: Action Buttons */}
                    <Space size="small" style={{ flexShrink: 0, alignSelf: "center" }}>
                        <Button
                            icon={<SyncOutlined spin={isSyncing} />}
                            loading={isSyncing}
                            onClick={() => onSync(account.id)}
                        >
                            Sync Profiles
                        </Button>
                        <Popconfirm
                            title="Delete Account"
                            description="Are you sure you want to remove this account and all linked profiles?"
                            onConfirm={() => onDelete(account.id)}
                            okText="Delete"
                            cancelText="Cancel"
                            okButtonProps={{ danger: true }}
                        >
                            <Button danger icon={<DeleteOutlined />} />
                        </Popconfirm>
                    </Space>
                </div>
            }
        >
            {!hasProfiles ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="No game profiles found. Click 'Sync Profiles' to fetch them."
                />
            ) : (
                <Row gutter={[16, 16]}>
                    {account.game_profiles.map((profile) => (
                        <Col xs={24} sm={12} md={8} lg={6} key={profile.id}>
                            <GameProfileCard
                                profile={profile}
                                accountId={account.id}
                                accountCanRedeemCodes={canRedeemCodes}
                                onOpenSettings={(profileId) => onOpenProfileSettings(account.id, canRedeemCodes, profileId)}
                            />
                        </Col>
                    ))}
                </Row>
            )}
        </Card>
    );
}