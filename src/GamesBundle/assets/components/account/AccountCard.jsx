import { useGameProvider } from "@GamesBundle/components/GameProviderContext";
import GameProfileCard from "@GamesBundle/components/game-profile/GameProfileCard";
import {
    UserOutlined,
    SyncOutlined,
    DeleteOutlined,
    KeyOutlined,
} from "@ant-design/icons";
import { Card, Space, Button, Popconfirm, Typography, Row, Col, Empty } from "antd";
import React from "react";

const { Text } = Typography;

/**
 * Account card with its game profiles. Provider-specific credential status comes from
 * provider.AccountStatusTags and provider.getReloginState(account).
 */
export default function AccountCard({
    account,
    isSyncing,
    onSync,
    onRelogin,
    onDelete,
    onOpenProfileSettings,
}) {
    const { AccountStatusTags, getReloginState } = useGameProvider();
    const hasProfiles = account.game_profiles && account.game_profiles.length > 0;
    const reloginState = getReloginState?.(account) ?? { highlight: false, danger: false };

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

                        {/* Status badges wrapped cleanly to avoid collisions on smaller screens.
                            Flex gap instead of <Space>: the provider component renders a fragment,
                            which <Space> would treat as a single item without spacing. */}
                        {AccountStatusTags && (
                            <div style={{ display: "flex", flexWrap: "wrap", gap: 6 }}>
                                <AccountStatusTags account={account} />
                            </div>
                        )}
                    </div>

                    {/* Right section: Action Buttons */}
                    <Space size="small" style={{ flexShrink: 0, alignSelf: "center" }} wrap>
                        {onRelogin && (
                            <Button
                                type={reloginState.highlight ? "primary" : "default"}
                                danger={reloginState.danger}
                                icon={<KeyOutlined />}
                                onClick={() => onRelogin(account)}
                            >
                                Re-login
                            </Button>
                        )}
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
                                account={account}
                                onOpenSettings={(profileId) => onOpenProfileSettings(account.id, profileId)}
                            />
                        </Col>
                    ))}
                </Row>
            )}
        </Card>
    );
}
