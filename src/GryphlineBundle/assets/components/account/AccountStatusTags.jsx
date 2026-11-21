import SessionStatusTag from "@GamesBundle/components/account/SessionStatusTag";
import React from "react";

/**
 * Gryphline credential status badges shown in the shared AccountCard header.
 *
 * The SKPort session is short-lived and renewed on demand from the account token, so its expiry is not shown:
 * the badge turns red only when a renewal failed (token_refresh_failed).
 */
export default function AccountStatusTags({ account }) {
    return (
        <SessionStatusTag
            refreshFailed={Boolean(account.token_refresh_failed)}
            lastRefreshedAt={account.last_token_refreshed_at}
        />
    );
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
