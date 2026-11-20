import { CloseCircleOutlined, GiftOutlined, ReloadOutlined, WarningOutlined } from "@ant-design/icons";
import { Tag, Tooltip } from "antd";
import React from "react";

/**
 * HoYoverse credential status badges shown in the shared AccountCard header.
 */
export default function AccountStatusTags({ account }) {
    const canRedeemCodes = Boolean(account.can_redeem_codes);
    const canAutoRenew = Boolean(account.can_auto_renew);
    const cookieRefreshFailed = Boolean(account.cookie_refresh_failed);

    return <>
        {canAutoRenew ? (
            <Tag color="green" icon={<ReloadOutlined />} style={{ margin: 0 }}>
                Auto-Renew Ready
            </Tag>
        ) : (
            <Tooltip title="Account cookie is missing 'stoken_v2'. Cookies cannot be automatically renewed and will expire.">
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

        {cookieRefreshFailed && (
            <Tooltip title="Automatic cookie refresh failed. Please re-login to renew your HoYoverse tokens.">
                <Tag color="error" icon={<CloseCircleOutlined />} style={{ margin: 0 }}>
                    Refresh Failed
                </Tag>
            </Tooltip>
        )}
    </>;
}

/**
 * Re-login button emphasis: highlight when cookies cannot auto-renew, danger when refresh failed.
 */
export function getReloginState(account) {
    const cookieRefreshFailed = Boolean(account.cookie_refresh_failed);

    return {
        highlight: !account.can_auto_renew || cookieRefreshFailed,
        danger: cookieRefreshFailed,
    };
}
