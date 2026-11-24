import { DiscordFilled } from "@ant-design/icons";
import LinkedAccountRow from "@CoreBundle/components/profile/LinkedAccountRow";
import { useProfileApi } from "@CoreBundle/components/profile/ProfileApiContext";
import { App, Typography } from "antd";
import { useSearchParams } from "react-router";
import React from "react";

// Discord's "blurple"
const DISCORD_COLOR = '#5865F2';
// Starts the OAuth flow on the server (DiscordLinkController), which comes back to /profile?discord=<result>
const CONNECT_URL = '/profile/discord/connect';
const RESULT_PARAM = 'discord';
// A Map and not an object: the value comes from the URL, so "constructor" and the like must not match
const RESULT_MESSAGES = new Map([
    ['linked', { type: 'success', content: 'Discord account linked.' }],
    ['cancelled', { type: 'info', content: 'Discord linking cancelled.' }],
    ['already_linked', { type: 'error', content: 'This Discord account is already linked to another user.' }],
    ['unavailable', { type: 'error', content: 'Discord linking is not configured on this site.' }],
    ['error', { type: 'error', content: 'Could not link the Discord account. Try again.' }],
]);

/**
 * Maps the Discord account to the generic row. The ids set by hand before the OAuth flow have no stored names
 * until linked again: they get a generic title and a hint to link again.
 *
 * @param {import("./ProfileApi").DiscordAccount|null} discord
 * @returns {import("./LinkedAccountRow").LinkedAccount|null}
 */
function toLinkedAccount(discord) {
    if (!discord?.linked) {
        return null;
    }

    return {
        title: discord.global_name ?? discord.username ?? 'Discord account',
        subtitle: discord.username
            ? `@${discord.username}`
            : <Typography.Link href={CONNECT_URL}>Link again to show the account name</Typography.Link>,
        avatarUrl: discord.avatar_url,
    };
}

/**
 * Discord row of the "Connected accounts" card (/api/me/discord). Also tells the outcome of the linking, which
 * the server reports with the "discord" query parameter when it sends the browser back to the profile.
 */
export default function DiscordAccountRow() {
    const api = useProfileApi();
    const { message } = App.useApp();
    const [searchParams, setSearchParams] = useSearchParams();
    // null until loaded, and after a failed request
    const [discord, setDiscord] = React.useState(null);
    const [loading, setLoading] = React.useState(true);

    // Returns the promise so that unlink() keeps its confirmation spinning until the row is up to date
    const load = React.useCallback(() => api.withErrorHandling().getDiscord()
        .then(res => setDiscord(res.data))
        .catch(() => setDiscord(null))
        .finally(() => setLoading(false)), [api]);

    React.useEffect(() => {
        load();
    }, [load]);

    // has() and not get(): an empty "?discord=" must be cleaned too
    const result = searchParams.has(RESULT_PARAM) ? searchParams.get(RESULT_PARAM) : null;
    React.useEffect(() => {
        if (result === null) {
            return;
        }
        const notice = RESULT_MESSAGES.get(result);
        if (notice) {
            // The key keeps a single message even if the effect runs again before the URL is cleaned
            message.open({ key: 'discord-link', ...notice });
        }
        // Replace: a reload or Back must not tell it again
        setSearchParams(params => {
            params.delete(RESULT_PARAM);
            return params;
        }, { replace: true });
    }, [result, message, setSearchParams]);

    const retry = () => {
        setLoading(true);
        load();
    };

    const unlink = () => api.withLoadingMessage({
        key: 'discord-unlink',
        loadingContent: 'Unlinking the Discord account...',
        successContent: 'Discord account unlinked',
    }).unlinkDiscord()
        .then(load)
        .catch(() => {});

    return <LinkedAccountRow
        icon={<DiscordFilled/>}
        color={DISCORD_COLOR}
        name="Discord"
        description="Receive notifications and use the bot with your Discord account."
        loading={loading}
        error={!loading && discord === null}
        account={toLinkedAccount(discord)}
        linkHref={CONNECT_URL}
        onUnlink={unlink}
        unlinkDescription="You will no longer receive notifications or use the bot with it."
        onRetry={retry}
    />;
}
