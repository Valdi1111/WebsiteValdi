import { ReloadOutlined } from "@ant-design/icons";
import { Avatar, Button, Flex, Popconfirm, Skeleton, theme, Tooltip, Typography } from "antd";
import React from "react";

const AVATAR_SIZE = 40;
const BADGE_SIZE = 18;

/**
 * An external account as shown in its row, already mapped from the provider's own payload.
 *
 * @typedef {Object} LinkedAccount
 * @property {string} title display name of the account
 * @property {React.ReactNode} [subtitle] e.g. the @username, shown under the title
 * @property {string|null} [avatarUrl] the initial of the title replaces it when missing or broken
 */

/**
 * Avatar of the linked account, with the provider logo as a small badge in the corner: one picture per row, and
 * the provider is still recognizable when several accounts are linked.
 */
function AccountAvatar({ account, icon, color, name }) {
    const { token } = theme.useToken();

    return <span style={{ position: 'relative', display: 'inline-flex', flex: 'none' }}>
        <Avatar size={AVATAR_SIZE} src={account.avatarUrl || undefined} alt={account.title} style={{ backgroundColor: color }}>
            {account.title.charAt(0).toUpperCase()}
        </Avatar>
        <Tooltip title={name}>
            <span
                role="img"
                aria-label={name}
                style={{
                    position: 'absolute',
                    right: -3,
                    bottom: -3,
                    width: BADGE_SIZE,
                    height: BADGE_SIZE,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    borderRadius: '50%',
                    // The ring of the card background detaches the badge from the avatar, in both themes
                    border: `2px solid ${token.colorBgContainer}`,
                    backgroundColor: color,
                    color: '#fff',
                    fontSize: 10,
                }}
            >
                {icon}
            </span>
        </Tooltip>
    </span>;
}

/**
 * Row of an external account (Discord, Telegram...) in the "Connected accounts" card: the linked account with the
 * unlink button, or what linking is for with the link button. Provider-agnostic: each provider maps its data to
 * these props.
 *
 * @param {Object} props
 * @param {React.ReactNode} props.icon logo of the provider
 * @param {string} [props.color] brand color of the provider, behind its logo and the account initial
 * @param {string} props.name name of the provider
 * @param {React.ReactNode} props.description what linking is for, shown while not linked
 * @param {boolean} props.loading
 * @param {boolean} [props.error] the account could not be loaded: neither linking nor unlinking is offered
 * @param {LinkedAccount|null} props.account null when not linked
 * @param {string} props.linkHref page that starts the linking (a full-page navigation, e.g. to an OAuth flow)
 * @param {() => Promise<any>} props.onUnlink resolved when done: the confirm button spins until then
 * @param {React.ReactNode} [props.unlinkDescription] consequences of unlinking, in the confirmation
 * @param {() => void} [props.onRetry]
 */
export default function LinkedAccountRow({
    icon,
    color,
    name,
    description,
    loading,
    error = false,
    account,
    linkHref,
    onUnlink,
    unlinkDescription,
    onRetry,
}) {
    // Linked: the account itself; otherwise the provider, with what linking is for or why it cannot be shown
    let avatar = <Avatar size={AVATAR_SIZE} icon={icon} style={{ backgroundColor: color, flex: 'none' }}/>;
    let title = name;
    let subtitle;
    let action;
    if (error) {
        subtitle = `Could not load your ${name} account.`;
        action = onRetry && <Button icon={<ReloadOutlined/>} onClick={onRetry}>Retry</Button>;
    } else if (account) {
        avatar = <AccountAvatar account={account} icon={icon} color={color} name={name}/>;
        title = account.title;
        subtitle = account.subtitle;
        action = <Popconfirm
            title={`Unlink your ${name} account?`}
            description={unlinkDescription}
            okText="Unlink"
            okButtonProps={{ danger: true }}
            onConfirm={onUnlink}
        >
            <Button danger>Unlink</Button>
        </Popconfirm>;
    } else {
        subtitle = description;
        // A plain link and not a router one: the server runs the linking, then sends the browser back here
        action = <Button type="primary" icon={icon} href={linkHref}>{`Link ${name}`}</Button>;
    }

    return <Skeleton loading={loading} active avatar paragraph={{ rows: 1 }}>
        <Flex align="center" gap="middle" wrap>
            {avatar}
            <Flex vertical style={{ flex: 1, minWidth: 200 }}>
                <Typography.Text strong ellipsis>{title}</Typography.Text>
                {/* Only the @username is cut: a description, a hint or a link must stay readable on narrow screens */}
                {subtitle && <Typography.Text type="secondary" ellipsis={Boolean(account) && typeof subtitle === 'string'}>
                    {subtitle}
                </Typography.Text>}
            </Flex>
            {action}
        </Flex>
    </Skeleton>;
}
