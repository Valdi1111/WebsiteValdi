import {
    CheckCircleOutlined,
    CloseCircleOutlined,
    KeyOutlined,
    LockOutlined,
    LoginOutlined,
    MailOutlined,
} from "@ant-design/icons";
import CaptchaStep from "@GamesBundle/components/account/CaptchaStep";
import useLoginWizard from "@GamesBundle/components/account/useLoginWizard";
import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { Alert, Button, Descriptions, Form, Input, Modal, Space, Tabs, Tag } from "antd";
import React, { useEffect, useMemo, useState } from "react";

export default function AddAccountModal({
    open,
    onCancel,
    onSubmit,
    onLoginSuccess,
    loading,
    reloginAccount = null,
}) {
    const api = useBackendApi();
    const [tokenForm] = Form.useForm();
    const [loginForm] = Form.useForm();

    const [activeTab, setActiveTab] = useState("login");

    const rawInput = Form.useWatch("account_token", tokenForm);

    // Wizard steps: credentials | captcha
    const wizard = useLoginWizard({
        api,
        reloginAccount,
        onLoginSuccess: (account) => {
            tokenForm.resetFields();
            onLoginSuccess?.(account);
        },
        onReset: () => loginForm.resetFields(),
    });
    const { step: wizardStep, loginSessionId, loading: wizardLoading, reset: resetWizard } = wizard;

    useEffect(() => {
        if (open) {
            setActiveTab("login");
            resetWizard();
            tokenForm.resetFields();
        }
    }, [open, reloginAccount, resetWizard, tokenForm]);

    const parsedToken = useMemo(() => {
        if (!rawInput || typeof rawInput !== "string") {
            return { token: "", isValid: false };
        }

        const trimmed = rawInput.trim();
        const match = trimmed.match(/(?:token|account_token)=([^&;\s]+)/i);
        const extracted = match ? decodeURIComponent(match[1]) : trimmed;

        return {
            token: extracted,
            isValid: extracted.length > 5,
        };
    }, [rawInput]);

    const handleClose = () => {
        resetWizard();
        tokenForm.resetFields();
        onCancel();
    };

    const handleLoginStart = (values) => {
        wizard.start({
            email: values.email?.trim(),
            password: values.password,
        });
    };

    const handleTokenFinish = (values) => {
        const tokenToSubmit = parsedToken.isValid ? parsedToken.token : values.account_token?.trim();
        const payload = reloginAccount
            ? { account_token: tokenToSubmit, account_id: reloginAccount.id }
            : { account_token: tokenToSubmit };
        onSubmit(payload, () => tokenForm.resetFields());
    };

    const renderLoginWizard = () => {
        if (wizardStep === "captcha" && loginSessionId) {
            return (
                <CaptchaStep
                    frameUrl={api.accounts().getGeetestFrameUrl(loginSessionId)}
                    onSuccess={wizard.submitCaptcha}
                    submitting={wizardLoading}
                    onBack={resetWizard}
                    onCancel={handleClose}
                />
            );
        }

        return (
            <Form form={loginForm} layout="vertical" onFinish={handleLoginStart}>
                <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
                    <Alert
                        type="info"
                        showIcon
                        title="Direct Gryphline / SKPort Login"
                        description="Your credentials are sent directly to Gryphline's authentication server (as.gryphline.com) to obtain your account_token and initialize your SKPort session. Your password is never stored. If you log in via Google/Apple without a password, set a password in the Gryphline User Center or use the 'Paste Token' tab."
                    />

                    <Form.Item
                        label="Email Address"
                        name="email"
                        rules={[
                            { required: true, message: "Please enter your Gryphline email address" },
                            { type: "email", message: "Please enter a valid email address" },
                        ]}
                    >
                        <Input
                            prefix={<MailOutlined />}
                            placeholder="user@example.com"
                            autoComplete="email"
                        />
                    </Form.Item>

                    <Form.Item
                        label="Password"
                        name="password"
                        rules={[{ required: true, message: "Please enter your password" }]}
                    >
                        <Input.Password
                            prefix={<LockOutlined />}
                            placeholder="Enter your Gryphline password"
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

    const renderPasteTokenTab = () => (
        <Form form={tokenForm} layout="vertical" onFinish={handleTokenFinish}>
            <Form.Item
                label="Gryphline / SKPort Account Token"
                name="account_token"
                extra="Paste your Gryphline account_token. You can find it from developer tools when logged in to skport.com or gryphline.com."
                rules={[{ required: true, message: "Account token is required" }]}
            >
                <Input.TextArea rows={4} placeholder="Enter your account_token or login URL containing token=..." />
            </Form.Item>

            {rawInput && (
                <div style={{ marginBottom: 20 }}>
                    <Descriptions
                        title={
                            <Space size={6}>
                                <KeyOutlined />
                                <span>Extracted Credentials</span>
                            </Space>
                        }
                        size="small"
                        column={1}
                        bordered
                    >
                        <Descriptions.Item label={<strong>Required (Account Token)</strong>}>
                            <Space wrap>
                                <Tag
                                    color={parsedToken.isValid ? "success" : "error"}
                                    icon={parsedToken.isValid ? <CheckCircleOutlined /> : <CloseCircleOutlined />}
                                >
                                    account_token {parsedToken.isValid ? `(${parsedToken.token.length} chars)` : ""}
                                </Tag>
                            </Space>
                        </Descriptions.Item>
                    </Descriptions>

                    <div style={{ marginTop: 12 }}>
                        {parsedToken.isValid ? (
                            <Alert
                                type="success"
                                showIcon
                                title="Token Ready"
                                description="A valid token was detected. After saving, click 'Sync Profiles' to load your game roles."
                            />
                        ) : (
                            <Alert
                                type="warning"
                                showIcon
                                title="Invalid Token"
                                description="The provided token appears too short or invalid. Please check your credentials."
                            />
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
                        disabled={Boolean(rawInput && !parsedToken.isValid)}
                    >
                        Save
                    </Button>
                </Space>
            </Form.Item>
        </Form>
    );

    return (
        <Modal
            title={reloginAccount ? `Re-login Account #${reloginAccount.id}` : "Add Gryphline Account"}
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
                                <span>Login with Gryphline</span>
                            </Space>
                        ),
                        children: renderLoginWizard(),
                    },
                    {
                        key: "token",
                        label: (
                            <Space size={6}>
                                <KeyOutlined />
                                <span>Paste Token</span>
                            </Space>
                        ),
                        children: renderPasteTokenTab(),
                    },
                ]}
            />
        </Modal>
    );
}
