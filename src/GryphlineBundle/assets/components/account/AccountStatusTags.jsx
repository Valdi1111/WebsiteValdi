import { CheckCircleOutlined, CloseCircleOutlined, WarningOutlined } from "@ant-design/icons";
import { Tag, Tooltip } from "antd";
import React from "react";

/**
 * Gryphline credential status badges shown in the shared AccountCard header.
 */
export default function AccountStatusTags({ account }) {
    const isSessionValid = Boolean(account.is_session_valid ?? account.session_valid);
    const tokenRefreshFailed = Boolean(account.token_refresh_failed);

    return <>
        {isSessionValid ? (
            <Tag color="green" icon={<CheckCircleOutlined />} style={{ margin: 0 }}>
                Session Active
            </Tag>
        ) : (
            <Tooltip title="Session credentials have expired or have not been generated yet. Clicking 'Sync Profiles' or running any automation will attempt automatic renewal.">
                <Tag color="warning" icon={<WarningOutlined />} style={{ margin: 0 }}>
                    Session Expired / Pending
                </Tag>
            </Tooltip>
        )}

        {tokenRefreshFailed && (
            <Tooltip title="Automatic credential refresh failed. Please click Re-login to renew your Gryphline account token.">
                <Tag color="error" icon={<CloseCircleOutlined />} style={{ margin: 0 }}>
                    Token Refresh Failed
                </Tag>
            </Tooltip>
        )}
    </>;
}

/**
 * Re-login button emphasis: only when the automatic token refresh failed.
 */
export function getReloginState(account) {
    const tokenRefreshFailed = Boolean(account.token_refresh_failed);

    return {
        highlight: tokenRefreshFailed,
        danger: tokenRefreshFailed,
    };
}
