import { CheckCircleOutlined, ClockCircleOutlined, CloseCircleOutlined, KeyOutlined, MailOutlined } from "@ant-design/icons";
import { Tag, Tooltip } from "antd";
import React from "react";

const EXPIRY_WARNING_DAYS = 5;

function getDaysLeft(account) {
    if (!account.token_expires_at) {
        return null;
    }
    const expiresAt = new Date(account.token_expires_at).getTime();
    if (Number.isNaN(expiresAt)) {
        return null;
    }
    return Math.floor((expiresAt - Date.now()) / 86400000);
}

/**
 * Perfect World token status badges shown in the shared AccountCard header.
 */
export default function AccountStatusTags({ account }) {
    const daysLeft = getDaysLeft(account);
    const isExpired = Boolean(account.token_invalid) || (daysLeft !== null && daysLeft < 0);

    return <>
        {account.login_method === "token" ? (
            <Tag icon={<KeyOutlined />} style={{ margin: 0 }}>Manual Token</Tag>
        ) : (
            <Tooltip title={account.email}>
                <Tag icon={<MailOutlined />} style={{ margin: 0 }}>Email</Tag>
            </Tooltip>
        )}

        {isExpired ? (
            <Tooltip title="The Perfect World session token is no longer valid. Click Re-login to sign in again.">
                <Tag color="error" icon={<CloseCircleOutlined />} style={{ margin: 0 }}>
                    Login Expired
                </Tag>
            </Tooltip>
        ) : daysLeft !== null && (
            <Tooltip title={`Token valid until ${new Date(account.token_expires_at).toLocaleString()}`}>
                <Tag
                    color={daysLeft < EXPIRY_WARNING_DAYS ? "warning" : "green"}
                    icon={daysLeft < EXPIRY_WARNING_DAYS ? <ClockCircleOutlined /> : <CheckCircleOutlined />}
                    style={{ margin: 0 }}
                >
                    {daysLeft === 0 ? "Expires today" : `Expires in ${daysLeft} day${daysLeft === 1 ? "" : "s"}`}
                </Tag>
            </Tooltip>
        )}
    </>;
}

/**
 * Re-login button emphasis: token close to expiry or already expired.
 */
export function getReloginState(account) {
    const daysLeft = getDaysLeft(account);
    const isExpired = Boolean(account.token_invalid) || (daysLeft !== null && daysLeft < 0);

    return {
        highlight: isExpired || (daysLeft !== null && daysLeft < EXPIRY_WARNING_DAYS),
        danger: isExpired,
    };
}
