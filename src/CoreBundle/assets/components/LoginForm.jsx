import React from 'react';
import { Input, Button, Checkbox, Divider, Alert } from 'antd';
import { MailOutlined, LockOutlined, GoogleOutlined, FacebookFilled, TwitterOutlined } from '@ant-design/icons';
import AuthLayout from '@CoreBundle/components/auth/AuthLayout';

export default function LoginForm({
                                      csrfToken,
                                      lastUsername = '',
                                      error = null,
                                      notice = null,
                                      checkPath = '/login',
                                      forgotPasswordPath = '/forgot-password',
                                      imageUrl = ''
                                  }) {
    return (
        <AuthLayout imageUrl={imageUrl}>
            {notice && (
                <Alert
                    title={notice}
                    type="success"
                    showIcon
                    style={{ marginBottom: 24 }}
                />
            )}

            {error && (
                <Alert
                    title={error}
                    type="error"
                    showIcon
                    style={{ marginBottom: 24 }}
                />
            )}

            {/* Standard HTML POST form for Symfony Security handling */}
            <form action={checkPath} method="post" id="symfony-login-form">
                <input type="hidden" name="_csrf_token" value={csrfToken} />

                {/* Email / Username input */}
                <div style={{ marginBottom: 16 }}>
                    <Input
                        name="_username"
                        size="large"
                        prefix={<MailOutlined style={{ color: 'rgba(0,0,0,.25)' }} />}
                        placeholder="Email address"
                        defaultValue={lastUsername}
                        autoComplete="email"
                        required
                        autoFocus
                    />
                </div>

                {/* Password input with AntD built-in toggle */}
                <div style={{ marginBottom: 16 }}>
                    <Input.Password
                        name="_password"
                        size="large"
                        prefix={<LockOutlined style={{ color: 'rgba(0,0,0,.25)' }} />}
                        placeholder="Password"
                        autoComplete="current-password"
                        required
                    />
                </div>

                {/* Remember me checkbox */}
                <div style={{ marginBottom: 24, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <Checkbox name="_remember_me" defaultChecked>
                        Remember me
                    </Checkbox>
                    <a href={forgotPasswordPath}>Forgot password?</a>
                </div>

                {/* Submit button */}
                <Button type="primary" htmlType="submit" size="large" block>
                    Login
                </Button>
            </form>

            <Divider plain style={{ margin: '24px 0', color: '#8c8c8c' }}>OR</Divider>

            {/* Social login buttons */}
            <div style={{ display: 'flex', justifyContent: 'space-around', alignItems: 'center' }}>
                <Button
                    type="text"
                    shape="circle"
                    size="large"
                    icon={<GoogleOutlined style={{ fontSize: 24, color: '#dd4b39' }} />}
                    aria-label="Google sign-in"
                />
                <Button
                    type="text"
                    shape="circle"
                    size="large"
                    icon={<FacebookFilled style={{ fontSize: 24, color: '#3b5998' }} />}
                    aria-label="Facebook sign-in"
                />
                <Button
                    type="text"
                    shape="circle"
                    size="large"
                    icon={<TwitterOutlined style={{ fontSize: 24, color: '#55acee' }} />}
                    aria-label="Twitter sign-in"
                />
            </div>
        </AuthLayout>
    );
}
