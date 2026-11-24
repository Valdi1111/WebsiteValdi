import { PlusOutlined } from "@ant-design/icons";
import { useAdminApi } from "@CoreBundle/components/admin/AdminApiContext";
import CreateUserModal from "@CoreBundle/components/admin/CreateUserModal";
import LocalTable from "@CoreBundle/components/standard-table/LocalTable";
import { useCurrentUser } from "@CoreBundle/components/user/CurrentUserContext";
import { formatDateTimeFromIso } from "@CoreBundle/format-utils";
import { Button, Flex, Popconfirm, Switch, Tag, Tooltip, Typography } from "antd";
import React from "react";

// Assigned roles, as in the profile's RolesCard
const ROLE_COLOR = 'blue';

const LINK_CATALOG = {
    linked: { text: 'Linked', color: 'success' },
    not_linked: { text: 'Not linked', color: 'default' },
};

const STATUS_CATALOG = {
    active: { text: 'Active', color: 'success' },
    disabled: { text: 'Disabled', color: 'default' },
};

const byEmail = (a, b) => a.email.localeCompare(b.email);

/**
 * A linked Discord or Telegram account: its @username when known (not for the ids set by hand), else just "Linked".
 */
function LinkedAccountTag({ linked, username }) {
    const { text, color } = LINK_CATALOG[linked ? 'linked' : 'not_linked'];

    return <Tag color={color} style={{ marginInlineEnd: 0 }}>{linked && username ? `@${username}` : text}</Tag>;
}

/**
 * Enables or disables a user. Disabling asks for a confirmation first; the logged-in admin cannot disable
 * themselves (the backend refuses it too).
 *
 * @param {Object} props
 * @param {import("./AdminApi").AdminUser} props.user
 * @param {boolean} props.self whether it is the logged-in admin
 * @param {(enabled: boolean) => void} props.onChange
 */
function StatusSwitch({ user, self, onChange }) {
    if (self) {
        // A disabled switch fires no hover events: the span gets them for the tooltip
        return <Tooltip title="You cannot disable your own account">
            <span style={{ display: 'inline-block', cursor: 'not-allowed' }}>
                <Switch checked={user.enabled} checkedChildren="Active" unCheckedChildren="Disabled" disabled style={{ pointerEvents: 'none' }}/>
            </span>
        </Tooltip>;
    }

    const toggle = <Popconfirm
        title="Disable this user?"
        description={<>
            They are logged out at their next request and can no longer log in,<br/>
            not even with their API tokens, until you enable them again.
        </>}
        okText="Disable"
        okButtonProps={{ danger: true }}
        // Enabling needs no confirmation: the switch does it directly
        disabled={!user.enabled}
        onConfirm={() => onChange(false)}
    >
        <Switch
            checked={user.enabled}
            checkedChildren="Active"
            unCheckedChildren="Disabled"
            // Switching off only opens the confirmation, the switch stays on until it is confirmed
            onChange={(enabled) => enabled && onChange(true)}
        />
    </Popconfirm>;

    return user.disabled_at
        ? <Tooltip title={`Disabled on ${formatDateTimeFromIso(user.disabled_at)}`}>
            <span style={{ display: 'inline-block' }}>{toggle}</span>
        </Tooltip>
        : toggle;
}

/**
 * Admin page of the users (/api/admin/users): every user with roles, linked accounts and status. Users can be
 * created, enabled and disabled.
 */
export default function UsersPage() {
    const api = useAdminApi();
    const { user: currentUser } = useCurrentUser();
    const [users, setUsers] = React.useState([]);
    const [roles, setRoles] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const [creating, setCreating] = React.useState(false);

    const load = React.useCallback(() => {
        setLoading(true);
        Promise.all([
            api.withErrorHandling().getUsers().then(res => setUsers(res.data)),
            api.withErrorHandling().getRoles().then(res => setRoles(res.data)),
        ])
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [api]);

    React.useEffect(load, [load]);

    const replace = (user) => setUsers(list => list.map(u => u.id === user.id ? user : u));

    const setEnabled = (user, enabled) => api.withLoadingMessage({
        key: `user-${user.id}`,
        loadingContent: enabled ? 'Enabling the user...' : 'Disabling the user...',
        successContent: enabled ? 'User enabled' : 'User disabled',
    }).updateUser(user.id, { enabled })
        .then(res => replace(res.data))
        .catch(() => {});

    // Role code => tag in the cells and in the filter of the roles column
    const roleCatalog = React.useMemo(
        () => Object.fromEntries(roles.map(role => [role.name, { text: role.label, color: ROLE_COLOR }])),
        [roles]
    );

    // Flat string fields for the tag filters of LocalTable
    const rows = React.useMemo(() => users.map(user => ({
        ...user,
        discord: user.discord_linked ? 'linked' : 'not_linked',
        telegram: user.telegram_linked ? 'linked' : 'not_linked',
        status: user.enabled ? 'active' : 'disabled',
    })), [users]);

    const currentUserId = currentUser?.id;

    const columns = React.useMemo(() => [
        {
            title: 'Email',
            dataIndex: 'email',
            filterType: 'text',
            sorter: true,
        },
        {
            title: 'Roles',
            dataIndex: 'roles',
            filterType: 'tags',
            tagCatalog: roleCatalog,
            // A fixed width wraps the tags: the table is as wide as its content (scroll x "max-content")
            width: 360,
            render: (names) => names.length > 0
                ? <Flex wrap gap={6}>
                    {names.map(name => <Tooltip key={name} title={name}>
                        <Tag color={ROLE_COLOR} style={{ marginInlineEnd: 0 }}>{roleCatalog[name]?.text ?? name}</Tag>
                    </Tooltip>)}
                </Flex>
                : <Typography.Text type="secondary">No roles</Typography.Text>,
        },
        {
            title: 'Discord',
            dataIndex: 'discord',
            filterType: 'tags',
            tagCatalog: LINK_CATALOG,
            width: 160,
            render: (_, user) => <LinkedAccountTag linked={user.discord_linked} username={user.discord_username}/>,
        },
        {
            title: 'Telegram',
            dataIndex: 'telegram',
            filterType: 'tags',
            tagCatalog: LINK_CATALOG,
            width: 160,
            render: (_, user) => <LinkedAccountTag linked={user.telegram_linked} username={user.telegram_username}/>,
        },
        {
            title: 'Status',
            dataIndex: 'status',
            filterType: 'tags',
            tagCatalog: STATUS_CATALOG,
            width: 140,
            render: (_, user) => <StatusSwitch
                user={user}
                self={user.id === currentUserId}
                onChange={(enabled) => setEnabled(user, enabled)}
            />,
        },
    ], [api, roleCatalog, currentUserId]);

    return <Flex vertical gap="middle" style={{ width: '100%', maxWidth: 1200, margin: '0 auto', padding: 24, overflowY: 'auto' }}>
        <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
            Everyone who can log in to the site. The roles decide which pages a user can open. A new user receives an
            email with a link to choose the password. A disabled user is logged out and can no longer log in, not even
            with an API token, until enabled again.
        </Typography.Paragraph>
        <LocalTable
            rowKey="id"
            title="Users"
            columns={columns}
            dataSource={rows}
            loading={loading}
            pagination={false}
            onReload={load}
            extraToolbarActions={<Button type="primary" icon={<PlusOutlined/>} onClick={() => setCreating(true)}>New user</Button>}
        />
        <CreateUserModal
            open={creating}
            roles={roles}
            onClose={() => setCreating(false)}
            onCreated={(user) => setUsers(list => [...list.filter(u => u.id !== user.id), user].toSorted(byEmail))}
        />
    </Flex>;
}
