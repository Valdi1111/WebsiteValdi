import { ThunderboltOutlined } from "@ant-design/icons";
import { useAdminApi } from "@CoreBundle/components/admin/AdminApiContext";
import generatePassword from "@CoreBundle/components/admin/generatePassword";
import { toRoleOptions } from "@CoreBundle/components/admin/roles";
import { Alert, App, Button, Checkbox, Flex, Form, Input, Modal, Select, Space, Typography } from "antd";
import React from "react";

// Same as the backend constraints (User::$email, NewPassword::MIN_LENGTH), checked here to fail before sending
const EMAIL_MAX_LENGTH = 180;
const PASSWORD_MIN_LENGTH = 12;
const PASSWORD_MAX_LENGTH = 4096;
const GENERATED_PASSWORD_LENGTH = 20;
// Validity of the link of the welcome email, decided by the backend
const LINK_VALIDITY = '7 days';
// What a new user is given unless the admin changes it
const DEFAULT_ROLES = ['ROLE_USER'];
// Form fields the backend violations can point at
const FIELDS = ['email', 'password', 'roles'];

/**
 * The form of a new user, mounted only while it is shown: every opening of the modal starts from empty fields.
 */
function CreateUserForm({ roles, onCreated }) {
    const api = useAdminApi();
    const { message } = App.useApp();
    const [form] = Form.useForm();
    const setPassword = Form.useWatch('set_password', form);
    const [passwordVisible, setPasswordVisible] = React.useState(false);
    const [saving, setSaving] = React.useState(false);

    const roleOptions = React.useMemo(() => toRoleOptions(roles), [roles]);

    const generate = () => {
        form.setFields([{ name: 'password', value: generatePassword(GENERATED_PASSWORD_LENGTH), errors: [] }]);
        // The admin has to read it to give it to the user
        setPasswordVisible(true);
    };

    const onFinish = ({ email, set_password, password, roles }) => {
        // The unchecked box wins over a password typed before unchecking it
        const initialPassword = set_password ? password : null;
        setSaving(true);
        api.createUser(email, initialPassword, roles ?? [])
            .then(res => onCreated(res.data, initialPassword))
            .catch(err => {
                const data = err.response?.data;
                // The password field exists only while the box is checked
                const fields = set_password ? FIELDS : FIELDS.filter(name => name !== 'password');
                // e.g. roles[1] for one of the roles goes under the roles field
                const violations = (data?.violations ?? []).map(v => ({ ...v, field: v.propertyPath?.replace(/\[.*$/, '') }));
                // Violations of a field go under it, the others (and network errors) in a message
                const fieldViolations = violations.filter(v => fields.includes(v.field));
                if (fieldViolations.length > 0) {
                    form.setFields(fields.map(name => ({
                        name,
                        errors: fieldViolations.filter(v => v.field === name).map(v => v.title),
                    })));
                }
                if (fieldViolations.length === 0 || fieldViolations.length < violations.length) {
                    message.error(data?.detail ?? 'The user could not be created.');
                }
            })
            .finally(() => setSaving(false));
    };

    return <Form
        form={form}
        layout="vertical"
        initialValues={{ roles: DEFAULT_ROLES, set_password: false }}
        onFinish={onFinish}
        requiredMark={false}
    >
        <Form.Item
            name="email"
            label="Email"
            normalize={(value) => value?.trim()}
            rules={[
                { required: true, message: 'Enter the email address of the user.' },
                { type: 'email', message: 'Enter a valid email address.' },
                { max: EMAIL_MAX_LENGTH },
            ]}
        >
            {/* Not the admin's own login: no autofill from the password managers */}
            <Input inputMode="email" maxLength={EMAIL_MAX_LENGTH} autoComplete="off" autoFocus/>
        </Form.Item>
        <Form.Item
            name="roles"
            label="Roles"
            extra="Without roles the user can log in but cannot open any page."
        >
            <Select
                mode="multiple"
                allowClear
                placeholder="No roles"
                options={roleOptions}
                showSearch={{ optionFilterProp: ['label', 'value'] }}
                optionRender={(option) => <Flex justify="space-between" gap="middle">
                    <span>{option.data.label}</span>
                    <Typography.Text type="secondary">{option.value}</Typography.Text>
                </Flex>}
            />
        </Form.Item>
        <Form.Item name="set_password" valuePropName="checked" style={setPassword ? { marginBottom: 8 } : undefined}>
            <Checkbox>Set a password now</Checkbox>
        </Form.Item>
        {setPassword && <Form.Item
            label="Password"
            extra={`At least ${PASSWORD_MIN_LENGTH} characters and hard to guess. It is not sent by email: give it to the user, who can change it later.`}
        >
            <Space.Compact block>
                {/* noStyle: its errors are shown by the Form.Item around it */}
                <Form.Item
                    name="password"
                    noStyle
                    rules={[
                        { required: true, message: 'Enter a password or generate one.' },
                        { min: PASSWORD_MIN_LENGTH, message: `The password must be at least ${PASSWORD_MIN_LENGTH} characters long.` },
                        { max: PASSWORD_MAX_LENGTH },
                    ]}
                >
                    <Input.Password
                        autoComplete="new-password"
                        maxLength={PASSWORD_MAX_LENGTH}
                        visibilityToggle={{ visible: passwordVisible, onVisibleChange: setPasswordVisible }}
                    />
                </Form.Item>
                <Button icon={<ThunderboltOutlined/>} onClick={generate}>Generate</Button>
            </Space.Compact>
        </Form.Item>}
        <Alert
            type="info"
            showIcon
            title={`The new user receives an email with a link to choose the password, valid for ${LINK_VALIDITY}.`}
            style={{ marginBottom: 24 }}
        />
        <Button type="primary" htmlType="submit" loading={saving}>Create</Button>
    </Form>;
}

/**
 * What to tell the user after the creation, with the password set by the admin (if any): it is shown only here.
 */
function CreatedUser({ user, password, onDone }) {
    return <Flex vertical gap="middle">
        <Alert
            type="success"
            showIcon
            title="User created"
            description={<>
                We sent <Typography.Text strong>{user.email}</Typography.Text> a link to choose the password
                (valid for {LINK_VALIDITY}).
            </>}
        />
        {password !== null && <>
            <Alert
                type="warning"
                showIcon
                title="The password is shown only now"
                description="It is not in the email: give it to the user, who can change it later."
            />
            <Typography.Text code copyable>{password}</Typography.Text>
        </>}
        <Button type="primary" onClick={onDone}>Done</Button>
    </Flex>;
}

/**
 * Creation of a user (POST /api/admin/users): email, roles and an optional initial password, then a summary.
 *
 * @param {Object} props
 * @param {boolean} props.open
 * @param {import("./AdminApi").AdminRole[]} props.roles every role of the site, for the select
 * @param {() => void} props.onClose
 * @param {(user: import("./AdminApi").AdminUser) => void} props.onCreated
 */
export default function CreateUserModal({ open, roles, onClose, onCreated }) {
    // { user, password } once created, password null when the user chooses it from the email
    const [created, setCreated] = React.useState(null);

    const close = () => {
        setCreated(null);
        onClose();
    };

    return <Modal
        title="New user"
        open={open}
        onCancel={close}
        footer={null}
        destroyOnHidden
        // A click outside must not hide the password before it has been copied
        mask={{ closable: !created }}
    >
        {created
            ? <CreatedUser user={created.user} password={created.password} onDone={close}/>
            : <CreateUserForm
                roles={roles}
                onCreated={(user, password) => {
                    setCreated({ user, password });
                    onCreated(user);
                }}
            />}
    </Modal>;
}
