import SpinComponent from "@CoreBundle/components/SpinComponent";
import RolesCard from "@CoreBundle/components/profile/RolesCard";
import { useCurrentUser } from "@CoreBundle/components/user/CurrentUserContext";
import { Button, Card, Descriptions, Flex, Result, Typography } from "antd";
import React from "react";

export default function ProfilePage() {
    const { user, loading, reload } = useCurrentUser();

    if (!user) {
        return <SpinComponent loading={loading}>
            <Flex align="center" justify="center" style={{ width: '100%' }}>
                <Result
                    status="warning"
                    title="Could not load your profile"
                    extra={<Button type="primary" onClick={reload}>Retry</Button>}
                />
            </Flex>
        </SpinComponent>;
    }

    return <Flex vertical gap="middle" style={{ width: '100%', maxWidth: 720, margin: '0 auto', padding: 24, overflowY: 'auto' }}>
        <Card title="Account">
            <Descriptions
                column={1}
                items={[
                    {
                        key: 'email',
                        label: 'Email',
                        children: <Flex vertical>
                            <Typography.Text copyable>{user.email}</Typography.Text>
                            {/* The email is the login identifier: changing it needs its own flow */}
                            <Typography.Text type="secondary">It is your login and cannot be changed here.</Typography.Text>
                        </Flex>,
                    },
                ]}
            />
        </Card>
        <RolesCard/>
    </Flex>;
}
