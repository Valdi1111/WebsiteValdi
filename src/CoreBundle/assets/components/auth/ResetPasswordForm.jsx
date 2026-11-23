import { LockOutlined } from '@ant-design/icons';
import AuthLayout, { CsrfInput, FieldErrors } from '@CoreBundle/components/auth/AuthLayout';
import { Alert, Button, Input, Typography } from 'antd';
import React from 'react';

/**
 * New password from a reset link (ResetPasswordController::reset()), typed twice. A plain HTML form, handled by
 * Symfony: the rules (NewPassword) are checked there.
 */
export default function ResetPasswordForm({ action, csrf = null, email, password, confirmation, errors = [], minLength, loginPath, imageUrl = '' }) {
    return <AuthLayout imageUrl={imageUrl}>
        <Typography.Title level={3}>Choose a new password</Typography.Title>
        <Typography.Paragraph type="secondary">
            For the account {email}. At least {minLength} characters and hard to guess; after the change, every
            device is logged out.
        </Typography.Paragraph>

        {errors.map((error, i) => <Alert key={i} title={error} type="error" showIcon style={{ marginBottom: 24 }}/>)}

        <form action={action} method="post">
            <CsrfInput csrf={csrf}/>
            {/* Tells the password managers which account the new password belongs to */}
            <input type="text" name="username" value={email} autoComplete="username" readOnly hidden/>

            <div style={{ marginBottom: 16 }}>
                <Input.Password
                    name={password.name}
                    size="large"
                    prefix={<LockOutlined style={{ color: 'rgba(0,0,0,.25)' }}/>}
                    placeholder="New password"
                    status={password.errors.length > 0 ? 'error' : undefined}
                    autoComplete="new-password"
                    minLength={minLength}
                    required
                    autoFocus
                />
                <FieldErrors errors={password.errors}/>
            </div>

            <div style={{ marginBottom: 24 }}>
                <Input.Password
                    name={confirmation.name}
                    size="large"
                    prefix={<LockOutlined style={{ color: 'rgba(0,0,0,.25)' }}/>}
                    placeholder="Repeat the new password"
                    status={confirmation.errors.length > 0 ? 'error' : undefined}
                    autoComplete="new-password"
                    required
                />
                <FieldErrors errors={confirmation.errors}/>
            </div>

            <Button type="primary" htmlType="submit" size="large" block>
                Change password
            </Button>
        </form>

        <div style={{ marginTop: 24, textAlign: 'center' }}>
            <a href={loginPath}>Back to login</a>
        </div>
    </AuthLayout>;
}
