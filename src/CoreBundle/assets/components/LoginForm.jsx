import React from 'react';
import { Row, Col, Input, Button, Checkbox, Divider, Alert } from 'antd';
import { MailOutlined, LockOutlined, GoogleOutlined, FacebookFilled, TwitterOutlined } from '@ant-design/icons';

export default function LoginForm({
                                      csrfToken,
                                      lastUsername = '',
                                      error = null,
                                      checkPath = '/login',
                                      imageUrl = ''
                                  }) {
    return (
        <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '24px' }}>
            <div style={{ maxWidth: 1100, width: '100%' }}>
                <Row gutter={[48, 32]} align="middle" justify="center">
                    {/* Visual / Illustration column */}
                    {imageUrl && (
                        <Col xs={24} md={12} lg={12} style={{ textAlign: 'center' }}>
                            <img
                                src={imageUrl}
                                alt="Login visual"
                                style={{ width: '100%', maxWidth: 450, height: 'auto', display: 'inline-block' }}
                            />
                        </Col>
                    )}

                    {/* Authentication form column */}
                    <Col xs={24} md={12} lg={10}>
                        <div style={{ maxWidth: 400, margin: '0 auto' }}>
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
                        </div>
                    </Col>
                </Row>
            </div>
        </div>
    );
}
