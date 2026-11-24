import { SendOutlined } from "@ant-design/icons";
import LinkedAccountRow from "@CoreBundle/components/profile/LinkedAccountRow";
import { useProfileApi } from "@CoreBundle/components/profile/ProfileApiContext";
import { App, Typography } from "antd";
import { useSearchParams } from "react-router";
import React from "react";

// Telegram's blue; the paper plane stands for its logo, which @ant-design/icons does not have
const TELEGRAM_COLOR = '#229ED9';
// Starts the OpenID Connect flow on the server (TelegramLinkController), which comes back to /profile?telegram=<result>
const CONNECT_URL = '/profile/telegram/connect';
const RESULT_PARAM = 'telegram';
// A Map and not an object: the value comes from the URL, so "constructor" and the like must not match
const RESULT_MESSAGES = new Map([
    ['linked', { type: 'success', content: 'Telegram account linked: the bot sent you a message.' }],
    ['cancelled', { type: 'info', content: 'Telegram linking cancelled.' }],
    ['already_linked', { type: 'error', content: 'This Telegram account is already linked to another user.' }],
    ['messages_not_allowed', {
        type: 'warning',
        content: 'The bot cannot message you, so the account was not linked. Link again and turn on "Allow Messages".',
        duration: 8,
    }],
    ['unavailable', { type: 'error', content: 'Telegram linking is not configured on this site.' }],
    ['error', { type: 'error', content: 'Could not link the Telegram account. Try again.' }],
]);

/**
 * The picture URL with a parameter of its own at each load. Telegram's address stays the same when the user changes
 * picture and redirects to the current one, but browsers keep that redirect for an hour (max-age=3600): a new address
 * at each load shows the current picture at once. Telegram ignores the parameter.
 *
 * @param {string|null} url
 * @returns {string|null}
 */
function withLoadParameter(url) {
    if (!url) {
        return null;
    }
    try {
        const fresh = new URL(url);
        fresh.searchParams.set('v', Date.now().toString(36));
        return fresh.toString();
    } catch {
        return url;
    }
}

/**
 * Maps the Telegram account to the generic row. The ids set by hand before the OpenID Connect flow have no stored
 * names until linked again: they get a generic title and a hint to link again. An account linked from here always has
 * a name, and a username only when the user has set one.
 *
 * @param {import("./ProfileApi").TelegramAccount|null} telegram
 * @returns {import("./LinkedAccountRow").LinkedAccount|null}
 */
function toLinkedAccount(telegram) {
    if (!telegram?.linked) {
        return null;
    }

    let subtitle = null;
    if (telegram.username) {
        subtitle = `@${telegram.username}`;
    } else if (!telegram.name) {
        subtitle = <Typography.Link href={CONNECT_URL}>Link again to show the account name</Typography.Link>;
    }

    return {
        title: telegram.name ?? telegram.username ?? 'Telegram account',
        subtitle,
        // The initial replaces it when missing or when it does not load
        avatarUrl: telegram.avatar_url,
    };
}

/**
 * Telegram row of the "Connected accounts" card (/api/me/telegram). Also tells the outcome of the linking, which
 * the server reports with the "telegram" query parameter when it sends the browser back to the profile.
 */
export default function TelegramAccountRow() {
    const api = useProfileApi();
    const { message } = App.useApp();
    const [searchParams, setSearchParams] = useSearchParams();
    // null until loaded, and after a failed request
    const [telegram, setTelegram] = React.useState(null);
    const [loading, setLoading] = React.useState(true);

    // Returns the promise so that unlink() keeps its confirmation spinning until the row is up to date. The picture
    // parameter is set here and not at each render, which would load the picture again every time
    const load = React.useCallback(() => api.withErrorHandling().getTelegram()
        .then(res => setTelegram({ ...res.data, avatar_url: withLoadParameter(res.data.avatar_url) }))
        .catch(() => setTelegram(null))
        .finally(() => setLoading(false)), [api]);

    React.useEffect(() => {
        load();
    }, [load]);

    // has() and not get(): an empty "?telegram=" must be cleaned too
    const result = searchParams.has(RESULT_PARAM) ? searchParams.get(RESULT_PARAM) : null;
    React.useEffect(() => {
        if (result === null) {
            return;
        }
        const notice = RESULT_MESSAGES.get(result);
        if (notice) {
            // The key keeps a single message even if the effect runs again before the URL is cleaned
            message.open({ key: 'telegram-link', ...notice });
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
        key: 'telegram-unlink',
        loadingContent: 'Unlinking the Telegram account...',
        successContent: 'Telegram account unlinked',
    }).unlinkTelegram()
        .then(load)
        .catch(() => {});

    return <LinkedAccountRow
        icon={<SendOutlined/>}
        color={TELEGRAM_COLOR}
        name="Telegram"
        description="Receive notifications on Telegram from the bot of the site."
        loading={loading}
        error={!loading && telegram === null}
        account={toLinkedAccount(telegram)}
        linkHref={CONNECT_URL}
        onUnlink={unlink}
        unlinkDescription="You will no longer receive notifications on Telegram."
        onRetry={retry}
    />;
}
