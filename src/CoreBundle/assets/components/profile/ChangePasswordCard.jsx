import { useProfileApi } from "@CoreBundle/components/profile/ProfileApiContext";
import { useCurrentUser } from "@CoreBundle/components/user/CurrentUserContext";
import { App, Button, Card, Form, Input, Typography } from "antd";
import React from "react";

// Same as NewPassword::MIN_LENGTH in the backend, checked here to fail before sending
const MIN_LENGTH = 12;

/**
 * Change of the password with the current one (POST /api/me/password). The backend checks the rules of the new
 * password and answers 422 with the violations, shown next to their fields, or 429 after too many wrong current
 * passwords. This device stays logged in, the other ones are logged out.
 */
export default function ChangePasswordCard() {
    const { user } = useCurrentUser();
    const api = useProfileApi();
    const { message } = App.useApp();
    const [form] = Form.useForm();
    const [saving, setSaving] = React.useState(false);

    const onFinish = ({ current_password, new_password }) => {
        setSaving(true);
        api.changePassword(current_password, new_password)
            .then(() => {
                form.resetFields();
                message.success('Password changed: the other devices have been logged out.');
            })
            .catch(err => {
                const data = err.response?.data;
                const fields = ['current_password', 'new_password'];
                // Violations of a field go under it, the others (and 429, network errors) in a message
                const violations = data?.violations ?? [];
                const fieldViolations = violations.filter(v => fields.includes(v.propertyPath));
                if (fieldViolations.length > 0) {
                    form.setFields(fields.map(name => ({
                        name,
                        errors: fieldViolations.filter(v => v.propertyPath === name).map(v => v.title),
                    })));
                }
                if (fieldViolations.length === 0 || fieldViolations.length < violations.length) {
                    message.error(data?.detail ?? 'The password could not be changed.');
                }
            })
            .finally(() => setSaving(false));
    };

    return <Card title="Change password">
        <Form
            form={form}
            layout="vertical"
            initialValues={{ username: user.email }}
            onFinish={onFinish}
            requiredMark={false}
            style={{ maxWidth: 400 }}
        >
            {/* Tells the password managers which account the new password belongs to */}
            <Form.Item name="username" hidden>
                <Input autoComplete="username"/>
            </Form.Item>
            <Form.Item
                name="current_password"
                label="Current password"
                rules={[{ required: true, message: 'Enter your current password.' }]}
            >
                <Input.Password autoComplete="current-password"/>
            </Form.Item>
            <Form.Item
                name="new_password"
                label="New password"
                extra={`At least ${MIN_LENGTH} characters and hard to guess.`}
                dependencies={['current_password']}
                rules={[
                    { required: true, message: 'Enter a new password.' },
                    { min: MIN_LENGTH, message: `The new password must be at least ${MIN_LENGTH} characters long.` },
                    ({ getFieldValue }) => ({
                        validator: (_, value) => !value || value !== getFieldValue('current_password')
                            ? Promise.resolve()
                            : Promise.reject(new Error('The new password must be different from the current one.')),
                    }),
                ]}
            >
                <Input.Password autoComplete="new-password"/>
            </Form.Item>
            <Form.Item
                name="confirm_password"
                label="Repeat the new password"
                dependencies={['new_password']}
                rules={[
                    { required: true, message: 'Repeat the new password.' },
                    ({ getFieldValue }) => ({
                        validator: (_, value) => !value || value === getFieldValue('new_password')
                            ? Promise.resolve()
                            : Promise.reject(new Error('The two passwords do not match.')),
                    }),
                ]}
            >
                <Input.Password autoComplete="new-password"/>
            </Form.Item>
            <Typography.Paragraph type="secondary">
                This device stays logged in; the other ones are logged out. You will receive an email about the change.
            </Typography.Paragraph>
            <Button type="primary" htmlType="submit" loading={saving}>Change password</Button>
        </Form>
    </Card>;
}
