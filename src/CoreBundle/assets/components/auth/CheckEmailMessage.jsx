import AuthLayout from '@CoreBundle/components/auth/AuthLayout';
import { Button, Result } from 'antd';
import React from 'react';

/**
 * Page after a "forgot password" request (ResetPasswordController::checkEmail()). It reads the same whether an
 * account has the address or not.
 */
export default function CheckEmailMessage({ expiresIn, forgotPasswordPath, loginPath, imageUrl = '' }) {
    return <AuthLayout imageUrl={imageUrl}>
        <Result
            status="info"
            title="Check your email"
            subTitle={<>
                If an account matches the address, it has been sent a link to choose a new password.
                The link expires in {expiresIn}. Nothing in the inbox? Look in the spam folder,
                or <a href={forgotPasswordPath}>ask for a new link</a>.
            </>}
            extra={<Button type="primary" href={loginPath}>Back to login</Button>}
        />
    </AuthLayout>;
}
