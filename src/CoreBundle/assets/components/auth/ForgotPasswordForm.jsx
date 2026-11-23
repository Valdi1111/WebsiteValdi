import { MailOutlined } from '@ant-design/icons';
import AuthLayout, { CsrfInput, FieldErrors } from '@CoreBundle/components/auth/AuthLayout';
import { Alert, Button, Input, Typography } from 'antd';
import React from 'react';

/**
 * "Forgot password" page (ResetPasswordController::request()): the email to send the reset link to.
 * A plain HTML form, handled by Symfony like the login one.
 */
export default function ForgotPasswordForm({ action, csrf = null, email, errors = [], loginPath, imageUrl = '' }) {
    return <AuthLayout imageUrl={imageUrl}>
        <Typography.Title level={3}>Forgot your password?</Typography.Title>
        <Typography.Paragraph type="secondary">
            Enter the email address of your account: you will receive a link to choose a new password.
        </Typography.Paragraph>

        {errors.map((error, i) => <Alert key={i} title={error} type="error" showIcon style={{ marginBottom: 24 }}/>)}

        <form action={action} method="post">
            <CsrfInput csrf={csrf}/>

            <div style={{ marginBottom: 24 }}>
                <Input
                    name={email.name}
                    type="email"
                    size="large"
                    prefix={<MailOutlined style={{ color: 'rgba(0,0,0,.25)' }}/>}
                    placeholder="Email address"
                    defaultValue={email.value}
                    status={email.errors.length > 0 ? 'error' : undefined}
                    autoComplete="email"
                    required
                    autoFocus
                />
                <FieldErrors errors={email.errors}/>
            </div>

            <Button type="primary" htmlType="submit" size="large" block>
                Send the link
            </Button>
        </form>

        <div style={{ marginTop: 24, textAlign: 'center' }}>
            <a href={loginPath}>Back to login</a>
        </div>
    </AuthLayout>;
}
