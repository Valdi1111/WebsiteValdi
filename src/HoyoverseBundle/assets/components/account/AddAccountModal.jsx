import {
    CheckCircleOutlined,
    CloseCircleOutlined,
    KeyOutlined,
    LockOutlined,
    LoginOutlined,
    MailOutlined,
    UserOutlined,
} from "@ant-design/icons";
import GeetestCaptchaFrame from "@CoreBundle/components/GeetestCaptchaFrame";
import { useBackendApi } from "@HoyoverseBundle/components/BackendApiContext";
import { Alert, Button, Descriptions, Form, Input, Modal, Space, Tabs, Tag } from "antd";
import React, { useCallback, useEffect, useMemo, useState } from "react";

export default function AddAccountModal({
    open,
    onCancel,
    onSubmit,
    onLoginSuccess,
    loading,
    reloginAccount = null,
}) {
    const api = useBackendApi();
    const [cookieForm] = Form.useForm();
    const [loginForm] = Form.useForm();
    const [emailForm] = Form.useForm();

    const [activeTab, setActiveTab] = useState("login");
    const [wizardStep, setWizardStep] = useState("credentials"); // credentials | captcha | email_verification
    const [loginSessionId, setLoginSessionId] = useState(null);
    const [wizardLoading, setWizardLoading] = useState(false);
    const [resendCooldown, setResendCooldown] = useState(0);

    const rawCookie = Form.useWatch("cookie", cookieForm);

    const resetWizard = useCallback(() => {
        setWizardStep("credentials");
        setLoginSessionId(null);
        setWizardLoading(false);
        setResendCooldown(0);
        loginForm.resetFields();
        emailForm.resetFields();
    }, [loginForm, emailForm]);

    useEffect(() => {
        if (open) {
            setActiveTab("login");
            resetWizard();
            cookieForm.resetFields();
        }
    }, [open, reloginAccount, resetWizard, cookieForm]);

    useEffect(() => {
        if (resendCooldown <= 0) {
            return;
        }
        const timer = setInterval(() => {
            setResendCooldown((prev) => (prev > 1 ? prev - 1 : 0));
        }, 1000);
        return () => clearInterval(timer);
    }, [resendCooldown]);

    const parsedTokens = useMemo(() => {
        if (!rawCookie || typeof rawCookie !== "string") {
            return { tokens: {}, isValid: false, canRedeemCodes: false, canAutoRenew: false };
        }

        const tokens = {};
        for (const pair of rawCookie.split(";")) {
            const index = pair.indexOf("=");
            if (index > -1) {
                const key = pair.slice(0, index).trim();
                const value = decodeURIComponent(pair.slice(index + 1).trim());
                if (key) {
                    tokens[key] = value;
                }
            }
        }

        const isValid = Boolean(tokens.ltoken_v2 && tokens.ltuid_v2 && tokens.ltmid_v2);
        const canRedeemCodes = Boolean(tokens.cookie_token_v2 && tokens.account_mid_v2 && tokens.account_id_v2);
        const canAutoRenew = Boolean(tokens.stoken_v2);

        return { tokens, isValid, canRedeemCodes, canAutoRenew };
    }, [rawCookie]);

    const handleClose = () => {
        resetWizard();
        cookieForm.resetFields();
        onCancel();
    };

    const handleStepResult = (data) => {
        if (!data) {
            return;
        }
        const sessionId = data.login_session_id ?? data.loginSessionId ?? loginSessionId;
        if (sessionId) {
            setLoginSessionId(sessionId);
        }

        if (data.status === "captcha_required") {
            setWizardStep("captcha");
        } else if (data.status === "email_verification_required") {
            setWizardStep("email_verification");
            setResendCooldown(60);
        } else if (data.status === "authenticated") {
            resetWizard();
            cookieForm.resetFields();
            onLoginSuccess?.(data.account);
        }
    };

    const handleLoginStart = (values) => {
        setWizardLoading(true);
        const payload = {
            account: values.account?.trim(),
            password: values.password,
        };

        const reqPromise = reloginAccount
            ? api.withErrorHandling().accounts().reloginStart(reloginAccount.id, payload)
            : api.withErrorHandling().accounts().loginStart(payload);

        reqPromise
            .then((res) => handleStepResult(res.data))
            .catch(() => {})
            .finally(() => setWizardLoading(false));
    };

    const handleCaptchaSuccess = useCallback(
        (captchaPayload) => {
            if (!loginSessionId) {
                return;
            }
            setWizardLoading(true);
            api.withErrorHandling()
                .accounts()
                .loginCaptcha({
                    login_session_id: loginSessionId,
                    captcha: captchaPayload,
                })
                .then((res) => handleStepResult(res.data))
                .catch(() => {})
                .finally(() => setWizardLoading(false));
        },
        [api, loginSessionId]
    );

    const handleVerifyEmail = (values) => {
        if (!loginSessionId) {
            return;
        }
        setWizardLoading(true);
        api.withErrorHandling()
            .accounts()
            .loginVerifyEmail({
                login_session_id: loginSessionId,
                code: values.code?.trim(),
            })
            .then((res) => handleStepResult(res.data))
            .catch(() => {})
            .finally(() => setWizardLoading(false));
    };

    const handleResendEmail = () => {
        if (!loginSessionId || resendCooldown > 0) {
            return;
        }
        setWizardLoading(true);
        api.withErrorHandling()
            .accounts()
            .loginResendEmail({
                login_session_id: loginSessionId,
            })
            .then((res) => handleStepResult(res.data))
            .catch(() => {})
            .finally(() => setWizardLoading(false));
    };

    const handleCookieFinish = (values) => {
        const payload = reloginAccount
            ? { ...values, account_id: reloginAccount.id }
            : values;
        onSubmit(payload, () => cookieForm.resetFields());
    };

    const renderLoginWizard = () => {
        if (wizardStep === "captcha" && loginSessionId) {
            return (
                <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
                    <GeetestCaptchaFrame
                        frameUrl={api.accounts().getGeetestFrameUrl(loginSessionId)}
                        onSuccess={handleCaptchaSuccess}
                        submitting={wizardLoading}
                    />
                    <div style={{ textAlign: "right" }}>
                        <Space>
                            <Button onClick={resetWizard} disabled={wizardLoading}>
                                Back to Credentials
                            </Button>
                            <Button onClick={handleClose} disabled={wizardLoading}>
                                Cancel
                            </Button>
                        </Space>
                    </div>
                </Space>
            );
        }

        if (wizardStep === "email_verification") {
            return (
                <Form form={emailForm} layout="vertical" onFinish={handleVerifyEmail}>
                    <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
                        <Alert
                            type="info"
                            showIcon
                            title="Email Verification Required"
                            description="HoYoverse sent a verification code to your registered email address to authorize this device. Enter the 6-digit code below."
                        />

                        <Form.Item
                            label="Verification Code"
                            name="code"
                            rules={[{ required: true, message: "Please enter the verification code" }]}
                        >
                            <Input
                                prefix={<MailOutlined />}
                                placeholder="Enter 6-digit verification code"
                                maxLength={8}
                                autoComplete="one-time-code"
                            />
                        </Form.Item>

                        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 8 }}>
                            <Button
                                onClick={handleResendEmail}
                                disabled={wizardLoading || resendCooldown > 0}
                            >
                                {resendCooldown > 0 ? `Resend Code (${resendCooldown}s)` : "Resend Code"}
                            </Button>

                            <Space>
                                <Button onClick={resetWizard} disabled={wizardLoading}>
                                    Back
                                </Button>
                                <Button onClick={handleClose} disabled={wizardLoading}>
                                    Cancel
                                </Button>
                                <Button type="primary" htmlType="submit" loading={wizardLoading}>
                                    Verify & Sign In
                                </Button>
                            </Space>
                        </div>
                    </Space>
                </Form>
            );
        }

        return (
            <Form form={loginForm} layout="vertical" onFinish={handleLoginStart}>
                <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
                    <Alert
                        type="info"
                        showIcon
                        title="Direct HoYoverse Login (Auto-Renewal & Code Redemption)"
                        description="Your credentials are encrypted with HoYoverse's RSA public key and used exclusively to generate stoken_v2, ltoken_v2, and cookie_token_v2. Your password is never stored. If you log in via Google/Apple/PSN without a HoYoverse password, link an email/password in your HoYoverse Account Center or use the 'Paste Cookie' tab."
                    />

                    <Form.Item
                        label="Email or Username"
                        name="account"
                        rules={[{ required: true, message: "Please enter your HoYoverse email or username" }]}
                    >
                        <Input
                            prefix={<UserOutlined />}
                            placeholder="user@example.com"
                            autoComplete="username"
                        />
                    </Form.Item>

                    <Form.Item
                        label="Password"
                        name="password"
                        rules={[{ required: true, message: "Please enter your password" }]}
                    >
                        <Input.Password
                            prefix={<LockOutlined />}
                            placeholder="Enter your HoYoverse password"
                            autoComplete="current-password"
                        />
                    </Form.Item>

                    <Form.Item style={{ marginBottom: 0, textAlign: "right" }}>
                        <Space>
                            <Button onClick={handleClose} disabled={wizardLoading}>
                                Cancel
                            </Button>
                            <Button type="primary" htmlType="submit" icon={<LoginOutlined />} loading={wizardLoading}>
                                {reloginAccount ? "Re-login" : "Sign In"}
                            </Button>
                        </Space>
                    </Form.Item>
                </Space>
            </Form>
        );
    };

    const renderPasteCookieTab = () => (
        <Form form={cookieForm} layout="vertical" onFinish={handleCookieFinish}>
            <Form.Item
                label="HoYoverse / HoYoLAB Cookie"
                name="cookie"
                extra="Paste the full cookie header containing at least ltoken_v2, ltuid_v2, and ltmid_v2."
                rules={[{ required: true, message: "Cookie is required" }]}
            >
                <Input.TextArea rows={4} placeholder="ltoken_v2=...; ltuid_v2=...; ltmid_v2=...;" />
            </Form.Item>

            {rawCookie && (
                <div style={{ marginBottom: 20 }}>
                    <Descriptions
                        title={
                            <Space size={6}>
                                <KeyOutlined />
                                <span>Extracted Tokens</span>
                            </Space>
                        }
                        size="small"
                        column={1}
                        bordered
                    >
                        <Descriptions.Item label={<strong>Required (Account Access)</strong>}>
                            <Space wrap>
                                <Tag color={parsedTokens.tokens.ltoken_v2 ? "success" : "error"} icon={parsedTokens.tokens.ltoken_v2 ? <CheckCircleOutlined /> : <CloseCircleOutlined />}>
                                    ltoken_v2
                                </Tag>
                                <Tag color={parsedTokens.tokens.ltuid_v2 ? "success" : "error"} icon={parsedTokens.tokens.ltuid_v2 ? <CheckCircleOutlined /> : <CloseCircleOutlined />}>
                                    ltuid_v2
                                </Tag>
                                <Tag color={parsedTokens.tokens.ltmid_v2 ? "success" : "error"} icon={parsedTokens.tokens.ltmid_v2 ? <CheckCircleOutlined /> : <CloseCircleOutlined />}>
                                    ltmid_v2
                                </Tag>
                            </Space>
                        </Descriptions.Item>

                        <Descriptions.Item label={<strong>Auto Renewal Tokens</strong>}>
                            <Space wrap>
                                <Tag color={parsedTokens.tokens.stoken_v2 ? "success" : "default"} icon={parsedTokens.tokens.stoken_v2 ? <CheckCircleOutlined /> : <CloseCircleOutlined />}>
                                    stoken_v2
                                </Tag>
                            </Space>
                        </Descriptions.Item>

                        <Descriptions.Item label={<strong>Code Redemption Tokens</strong>}>
                            <Space wrap>
                                <Tag color={parsedTokens.tokens.cookie_token_v2 ? "success" : "default"} icon={parsedTokens.tokens.cookie_token_v2 ? <CheckCircleOutlined /> : <CloseCircleOutlined />}>
                                    cookie_token_v2
                                </Tag>
                                <Tag color={parsedTokens.tokens.account_mid_v2 ? "success" : "default"} icon={parsedTokens.tokens.account_mid_v2 ? <CheckCircleOutlined /> : <CloseCircleOutlined />}>
                                    account_mid_v2
                                </Tag>
                                <Tag color={parsedTokens.tokens.account_id_v2 ? "success" : "default"} icon={parsedTokens.tokens.account_id_v2 ? <CheckCircleOutlined /> : <CloseCircleOutlined />}>
                                    account_id_v2
                                </Tag>
                            </Space>
                        </Descriptions.Item>
                    </Descriptions>

                    <div style={{ marginTop: 12, display: "flex", flexDirection: "column", gap: 8 }}>
                        {!parsedTokens.isValid && (
                            <Alert
                                type="error"
                                showIcon
                                title="Invalid Cookie"
                                description="Missing required base credentials (ltoken_v2, ltuid_v2, or ltmid_v2). The account cannot be saved without them."
                            />
                        )}

                        {parsedTokens.isValid && (
                            <>
                                {parsedTokens.canAutoRenew ? (
                                    <Alert
                                        type="success"
                                        showIcon
                                        title="Auto-Renewal Enabled"
                                        description="The 'stoken_v2' token is present. The system will be able to refresh session cookies automatically."
                                    />
                                ) : (
                                    <Alert
                                        type="warning"
                                        showIcon
                                        title="Auto-Renewal Unavailable"
                                        description="Missing 'stoken_v2'. Cookies will not refresh automatically and will expire over time."
                                    />
                                )}

                                {parsedTokens.canRedeemCodes ? (
                                    <Alert
                                        type="success"
                                        showIcon
                                        title="Code Redemption Enabled"
                                        description="All 3 redemption tokens (cookie_token_v2, account_mid_v2, and account_id_v2) are present. Automatic promo code redemption will be functional."
                                    />
                                ) : (
                                    <Alert
                                        type="warning"
                                        showIcon
                                        title="Code Redemption Disabled"
                                        description="Missing one or more redemption tokens (cookie_token_v2, account_mid_v2, account_id_v2). Daily check-in and stamina checks will work, but automatic promo code redemption will not be supported."
                                    />
                                )}
                            </>
                        )}
                    </div>
                </div>
            )}

            <Form.Item style={{ marginBottom: 0, textAlign: "right" }}>
                <Space>
                    <Button onClick={handleClose}>Cancel</Button>
                    <Button
                        type="primary"
                        htmlType="submit"
                        loading={loading}
                        disabled={Boolean(rawCookie && !parsedTokens.isValid)}
                    >
                        Save
                    </Button>
                </Space>
            </Form.Item>
        </Form>
    );

    return (
        <Modal
            title={reloginAccount ? `Re-login Account #${reloginAccount.id}` : "Add HoYoverse Account"}
            open={open}
            width={640}
            onCancel={handleClose}
            footer={null}
            destroyOnHidden
        >
            <Tabs
                activeKey={activeTab}
                onChange={setActiveTab}
                items={[
                    {
                        key: "login",
                        label: (
                            <Space size={6}>
                                <LoginOutlined />
                                <span>Login with HoYoverse</span>
                            </Space>
                        ),
                        children: renderLoginWizard(),
                    },
                    {
                        key: "cookie",
                        label: (
                            <Space size={6}>
                                <KeyOutlined />
                                <span>Paste Cookie</span>
                            </Space>
                        ),
                        children: renderPasteCookieTab(),
                    },
                ]}
            />
        </Modal>
    );
}
