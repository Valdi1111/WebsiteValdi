import { EditOutlined, PlusOutlined } from "@ant-design/icons";
import { useAdminApi } from "@CoreBundle/components/admin/AdminApiContext";
import CreateUserModal from "@CoreBundle/components/admin/CreateUserModal";
import EditRolesModal from "@CoreBundle/components/admin/EditRolesModal";
import { RoleTags, RoleTagsLegend } from "@CoreBundle/components/admin/roles";
import LocalTable from "@CoreBundle/components/standard-table/LocalTable";
import { useCurrentUser } from "@CoreBundle/components/user/CurrentUserContext";
import { formatDateTimeFromIso } from "@CoreBundle/format-utils";
import { App, Button, Flex, Popconfirm, Switch, Tag, Tooltip, Typography } from "antd";
import React from "react";

// Tags of the roles filter, blue as the assigned roles
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
    const { message } = App.useApp();
    const { user: currentUser } = useCurrentUser();
    const [users, setUsers] = React.useState([]);
    const [roles, setRoles] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const [creating, setCreating] = React.useState(false);
    // The user whose roles are being edited
    const [editing, setEditing] = React.useState(null);

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

    const rolesByName = React.useMemo(() => Object.fromEntries(roles.map(role => [role.name, role])), [roles]);
    // Role code => tag in the filter of the roles column
    const roleCatalog = React.useMemo(
        () => Object.fromEntries(roles.map(role => [role.name, { text: role.label, color: ROLE_COLOR }])),
        [roles]
    );

    const currentUserId = currentUser?.id;

    const onRolesSaved = (user) => {
        setEditing(null);
        if (user.id === currentUserId) {
            // The session keeps the roles of the login: the next request logs out, and the reload shows the login page
            message.success('Roles saved: log in again to get them.', 2);
            setTimeout(() => window.location.reload(), 2000);
            return;
        }
        replace(user);
        message.success('Roles saved');
    };

    // Flat string fields for the tag filters of LocalTable
    const rows = React.useMemo(() => users.map(user => ({
        ...user,
        discord: user.discord_linked ? 'linked' : 'not_linked',
        telegram: user.telegram_linked ? 'linked' : 'not_linked',
        status: user.enabled ? 'active' : 'disabled',
    })), [users]);

    const columns = React.useMemo(() => [
        {
            title: 'Email',
            dataIndex: 'email',
            filterType: 'text',
            sorter: true,
        },
        {
            title: 'Roles',
            // Filtered on what users can do: assigned and inherited
            dataIndex: 'effective_roles',
            filterType: 'tags',
            tagCatalog: roleCatalog,
            // A fixed width wraps the tags: the table is as wide as its content (scroll x "max-content")
            width: 400,
            render: (_, user) => <Flex justify="space-between" align="start" gap="small">
                <RoleTags assigned={user.roles} effective={user.effective_roles} rolesByName={rolesByName}/>
                <Tooltip title="Edit the roles">
                    <Button size="small" type="text" icon={<EditOutlined/>} onClick={() => setEditing(user)} aria-label="Edit the roles"/>
                </Tooltip>
            </Flex>,
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
    ], [api, roleCatalog, rolesByName, currentUserId]);

    return <Flex vertical gap="middle" style={{ width: '100%', maxWidth: 1200, margin: '0 auto', padding: 24, overflowY: 'auto' }}>
        <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
            Everyone who can log in to the site. The roles decide which pages a user can open: the assigned ones and
            those they bring through the role hierarchy. A user whose roles change is logged out, and gets the new ones
            at the next login. A new user receives an email with a link to choose the password. A disabled user is
            logged out and can no longer log in, not even with an API token, until enabled again.
        </Typography.Paragraph>
        <LocalTable
            rowKey="id"
            title="Users"
            columns={columns}
            dataSource={rows}
            loading={loading}
            pagination={false}
            onReload={load}
            extraToolbarActions={<Flex align="center" gap="middle">
                <RoleTagsLegend/>
                <Button type="primary" icon={<PlusOutlined/>} onClick={() => setCreating(true)}>New user</Button>
            </Flex>}
        />
        <CreateUserModal
            open={creating}
            roles={roles}
            onClose={() => setCreating(false)}
            onCreated={(user) => setUsers(list => [...list.filter(u => u.id !== user.id), user].toSorted(byEmail))}
        />
        <EditRolesModal
            user={editing}
            roles={roles}
            isSelf={editing?.id === currentUserId}
            onClose={() => setEditing(null)}
            onSaved={onRolesSaved}
        />
    </Flex>;
}
