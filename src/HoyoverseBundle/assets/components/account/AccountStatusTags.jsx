import SessionStatusTag from "@GamesBundle/components/account/SessionStatusTag";
import { GiftOutlined, ReloadOutlined, WarningOutlined } from "@ant-design/icons";
import { Tag, Tooltip } from "antd";
import React from "react";

/**
 * HoYoverse credential status badges shown in the shared AccountCard header.
 *
 * - Session: red only when the automatic cookie renewal failed (cookie_refresh_failed), with the last renewal date.
 * - Auto-Renew / Code Redemption: capabilities read from the tokens present in the stored cookie, not a validity check.
 */
export default function AccountStatusTags({ account }) {
    const canRedeemCodes = Boolean(account.can_redeem_codes);
    const canAutoRenew = Boolean(account.can_auto_renew);
    const cookieRefreshFailed = Boolean(account.cookie_refresh_failed);

    return <>
        <SessionStatusTag refreshFailed={cookieRefreshFailed} lastRefreshedAt={account.last_cookie_refreshed_at} />

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
            <Tooltip title="Cookie is missing redemption tokens (cookie_token_v2, account_mid_v2, account_id_v2). New promo codes are notified for manual redemption instead of being redeemed automatically.">
                <Tag color="warning" icon={<WarningOutlined />} style={{ margin: 0 }}>
                    Code Redemption Unavailable
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
