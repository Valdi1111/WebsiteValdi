import { useAdminApi } from "@CoreBundle/components/admin/AdminApiContext";
import { ADMIN_ROLE, effectiveRoleNames, RoleTags, RoleTagsLegend, toRoleOptions } from "@CoreBundle/components/admin/roles";
import { Alert, App, Button, Flex, Form, Modal, Select, Typography } from "antd";
import React from "react";

const sameSet = (a, b) => a.length === b.length && a.every(name => b.includes(name));

/**
 * The form of the roles of a user, mounted only while the modal is open: it starts from the saved roles every time.
 */
function EditRolesForm({ user, roles, isSelf, onSaved, onCancel }) {
    const api = useAdminApi();
    const { message } = App.useApp();
    const [form] = Form.useForm();
    const [saving, setSaving] = React.useState(false);
    const selected = Form.useWatch('roles', form) ?? user.roles;

    const rolesByName = React.useMemo(() => Object.fromEntries(roles.map(role => [role.name, role])), [roles]);
    // Your own admin role cannot be removed: the backend refuses it too
    const options = React.useMemo(() => toRoleOptions(roles, isSelf ? [ADMIN_ROLE] : []), [roles, isSelf]);
    const changed = !sameSet(selected, user.roles);

    const save = ({ roles: names }) => {
        setSaving(true);
        api.setUserRoles(user.id, names ?? [])
            .then(res => onSaved(res.data))
            .catch(err => {
                const data = err.response?.data;
                // e.g. roles[1] for one of the roles goes under the field too
                const violations = (data?.violations ?? []).filter(v => v.propertyPath?.replace(/\[.*$/, '') === 'roles');
                if (violations.length > 0) {
                    form.setFields([{ name: 'roles', errors: violations.map(v => v.title) }]);
                } else {
                    message.error(data?.detail ?? 'The roles could not be saved.');
                }
            })
            .finally(() => setSaving(false));
    };

    return <Form form={form} layout="vertical" initialValues={{ roles: user.roles }} onFinish={save} requiredMark={false}>
        <Form.Item
            name="roles"
            label="Assigned roles"
            extra={isSelf ? 'You cannot remove the admin role from yourself.' : 'Without roles the user can log in but cannot open any page.'}
        >
            <Select
                mode="multiple"
                placeholder="No roles"
                options={options}
                showSearch={{ optionFilterProp: ['label', 'value'] }}
                optionRender={(option) => <Flex justify="space-between" gap="middle">
                    <span>{option.data.label}</span>
                    <Typography.Text type="secondary">{option.value}</Typography.Text>
                </Flex>}
            />
        </Form.Item>
        <Flex vertical gap="small" style={{ marginBottom: 24 }}>
            <Flex justify="space-between" align="center" gap="middle">
                <Typography.Text strong>Effective roles</Typography.Text>
                <RoleTagsLegend/>
            </Flex>
            <Typography.Text type="secondary">What the user can do: the assigned roles and those they bring.</Typography.Text>
            <RoleTags assigned={selected} effective={effectiveRoleNames(selected, rolesByName)} rolesByName={rolesByName}/>
        </Flex>
        {changed && <Alert
            type="warning"
            showIcon
            style={{ marginBottom: 24 }}
            title={isSelf ? 'You will be logged out' : `${user.email} will be logged out`}
            description={isSelf
                ? 'Your session keeps the roles you logged in with: after saving, log in again to get the new ones.'
                : 'At their next request, if logged in: the new roles apply when they log in again. Their API tokens get them at once.'}
        />}
        <Flex gap="small">
            <Button type="primary" htmlType="submit" loading={saving} disabled={!changed}>Save</Button>
            <Button onClick={onCancel}>Cancel</Button>
        </Flex>
    </Form>;
}

/**
 * Edits the assigned roles of a user (PUT /api/admin/users/{id}/roles), with a preview of the effective ones.
 *
 * @param {Object} props
 * @param {import("./AdminApi").AdminUser|null} props.user the user being edited, null when closed
 * @param {import("./AdminApi").AdminRole[]} props.roles every role of the site
 * @param {boolean} props.isSelf whether the user is the logged-in admin
 * @param {() => void} props.onClose
 * @param {(user: import("./AdminApi").AdminUser) => void} props.onSaved
 */
export default function EditRolesModal({ user, roles, isSelf, onClose, onSaved }) {
    return <Modal title={user ? `Roles of ${user.email}` : 'Roles'} open={user !== null} onCancel={onClose} footer={null} width={640} destroyOnHidden>
        {user && <EditRolesForm user={user} roles={roles} isSelf={isSelf} onSaved={onSaved} onCancel={onClose}/>}
    </Modal>;
}
