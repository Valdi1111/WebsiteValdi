import React from "react";
import { Card, Space, Button, Popconfirm, Typography, Row, Col, Empty } from "antd";
import { UserOutlined, SyncOutlined, DeleteOutlined } from "@ant-design/icons";
import HoyoverseGameProfileCard from "@HoyoverseBundle/components/game-profile/HoyoverseGameProfileCard";

const { Text } = Typography;

export default function HoyoverseAccountCard({
                                                 account,
                                                 isSyncing,
                                                 onSync,
                                                 onDelete,
                                                 onOpenProfileSettings
                                             }) {
    const hasProfiles = account.game_profiles && account.game_profiles.length > 0;

    return (
        <Card
            title={
                <Space>
                    <UserOutlined />
                    <span>Account #{account.id}</span>
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        (Added: {account.added_at ? new Date(account.added_at).toLocaleDateString() : "-"})
                    </Text>
                </Space>
            }
            extra={
                <Space>
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
                            <HoyoverseGameProfileCard
                                profile={profile}
                                onOpenSettings={(profileId) => onOpenProfileSettings(account.id, profileId)}
                            />
                        </Col>
                    ))}
                </Row>
            )}
        </Card>
    );
}