import SpinComponent from "@CoreBundle/components/SpinComponent";
import AvatarEditor from "@CoreBundle/components/profile/AvatarEditor";
import ChangePasswordCard from "@CoreBundle/components/profile/ChangePasswordCard";
import ConnectedAccountsCard from "@CoreBundle/components/profile/ConnectedAccountsCard";
import RolesCard from "@CoreBundle/components/profile/RolesCard";
import { useCurrentUser } from "@CoreBundle/components/user/CurrentUserContext";
import { Button, Card, Descriptions, Divider, Flex, Result, Typography } from "antd";
import React from "react";

// Below about 420px each, the second column wraps under the first
const COLUMN_STYLE = { flex: '1 1 420px', minWidth: 0 };

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

    // The whole width scrolls, so that the scrollbar stays at the edge of the page and not next to the cards
    return <div style={{ width: '100%', padding: 24, overflowY: 'auto' }}>
        {/* Two columns side by side when both fit, one under the other otherwise (Account first) */}
        <Flex wrap gap="middle" align="flex-start" style={{ maxWidth: 1280, margin: '0 auto' }}>
            <Flex vertical gap="middle" style={COLUMN_STYLE}>
                <Card title="Account">
                    <AvatarEditor/>
                    <Divider/>
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
                <ChangePasswordCard/>
            </Flex>
            <Flex vertical gap="middle" style={COLUMN_STYLE}>
                <ConnectedAccountsCard/>
                <RolesCard/>
            </Flex>
        </Flex>
    </div>;
}
