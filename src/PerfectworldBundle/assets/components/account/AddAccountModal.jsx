import { KeyOutlined, LoginOutlined, MailOutlined } from "@ant-design/icons";
import useLoginWizard from "@GamesBundle/components/account/useLoginWizard";
import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { Alert, Button, Form, Input, Modal, Space, Tabs, Typography } from "antd";
import React, { useEffect, useMemo, useState } from "react";

const { Text } = Typography;

/**
 * Parses the "_websdk_" Local Storage value ({"uid":…,"token":…}) for the live preview.
 */
function parseWebsdk(raw) {
    if (!raw || typeof raw !== "string") {
        return null;
    }
    try {
        const data = JSON.parse(raw.trim());
        const uid = data?.uid !== undefined ? String(data.uid).trim() : "";
        const token = typeof data?.token === "string" ? data.token.trim() : "";
        return /^\d+$/.test(uid) && token.length >= 8 ? { uid, token } : null;
    } catch {
        return null;
    }
}

/**
 * Perfect World account: email + code login (no password, no captcha) or a pasted website token.
 */
export default function AddAccountModal({
    open,
    onCancel,
    onSubmit,
    onLoginSuccess,
    loading,
    reloginAccount = null,
}) {
    const api = useBackendApi();
    const [loginForm] = Form.useForm();
    const [codeForm] = Form.useForm();
    const [tokenForm] = Form.useForm();

    const [activeTab, setActiveTab] = useState("login");
    const [resendCooldown, setResendCooldown] = useState(0);
    const [codeSentTo, setCodeSentTo] = useState(null);
    const [tokenReloginLoading, setTokenReloginLoading] = useState(false);

    const rawWebsdk = Form.useWatch("websdk", tokenForm);
    const parsedWebsdk = useMemo(() => parseWebsdk(rawWebsdk), [rawWebsdk]);

    // Wizard steps: credentials (email) | email_code
    const wizard = useLoginWizard({
        api,
        reloginAccount,
        onLoginSuccess: (account) => {
            tokenForm.resetFields();
            onLoginSuccess?.(account);
        },
        onStatus: (data, { setStep }) => {
            if (data.status === "email_verification_required") {
                setStep("email_code");
                setResendCooldown(60);
            }
        },
        // The code form uses preserve={false}: it is cleared on unmount and may never have been mounted
        onReset: () => {
            setResendCooldown(0);
            setCodeSentTo(null);
            loginForm.resetFields();
        },
    });
    const { step: wizardStep, loginSessionId, loading: wizardLoading, reset: resetWizard } = wizard;

    useEffect(() => {
        if (open) {
            setActiveTab("login");
            resetWizard();
            tokenForm.resetFields();
        }
    }, [open, reloginAccount, resetWizard, tokenForm]);

    useEffect(() => {
        if (open && reloginAccount?.email) {
            loginForm.setFieldsValue({ email: reloginAccount.email });
        }
    }, [open, reloginAccount, loginForm, wizardStep]);

    useEffect(() => {
        if (resendCooldown <= 0) {
            return;
        }
        const timer = setInterval(() => {
            setResendCooldown((prev) => (prev > 1 ? prev - 1 : 0));
        }, 1000);
        return () => clearInterval(timer);
    }, [resendCooldown]);

    const handleClose = () => {
        resetWizard();
        tokenForm.resetFields();
        onCancel();
    };

    const handleSendCode = (values) => {
        const email = values.email?.trim();
        setCodeSentTo(email);
        wizard.start({ email });
    };

    const handleVerifyCode = (values) => {
        if (!loginSessionId) {
            return;
        }
        wizard.run(api.withErrorHandling().accounts().loginVerifyEmail({
            login_session_id: loginSessionId,
            code: values.code?.trim(),
        }));
    };

    const handleResendCode = () => {
        if (!loginSessionId || resendCooldown > 0) {
            return;
        }
        wizard.run(api.withErrorHandling().accounts().loginResendEmail({
            login_session_id: loginSessionId,
        }));
    };

    const handleTokenFinish = (values) => {
        const payload = { websdk: values.websdk?.trim() };
        if (!reloginAccount) {
            onSubmit(payload, () => tokenForm.resetFields());
            return;
        }

        setTokenReloginLoading(true);
        api.withErrorHandling()
            .accounts()
            .tokenRelogin(reloginAccount.id, payload)
            .then((res) => {
                tokenForm.resetFields();
                onLoginSuccess?.(res.data);
            })
            .catch(() => {})
            .finally(() => setTokenReloginLoading(false));
    };

    const renderLoginTab = () => {
        if (wizardStep === "email_code") {
            return (
                <Form form={codeForm} layout="vertical" preserve={false} onFinish={handleVerifyCode}>
                    <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
                        <Alert
                            type="info"
                            showIcon
                            title="Check your email"
                            description={`Perfect World sent a verification code to ${codeSentTo ?? "your email address"}. Enter it below to sign in.`}
                        />

                        <Form.Item
                            label="Verification Code"
                            name="code"
                            rules={[{ required: true, message: "Please enter the verification code" }]}
                        >
                            <Input
                                prefix={<MailOutlined />}
                                placeholder="Enter the verification code"
                                maxLength={10}
                                autoComplete="one-time-code"
                            />
                        </Form.Item>

                        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 8 }}>
                            <Button onClick={handleResendCode} disabled={wizardLoading || resendCooldown > 0}>
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
            <Form form={loginForm} layout="vertical" onFinish={handleSendCode}>
                <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
                    <Alert
                        type="info"
                        showIcon
                        title="Perfect World Games login"
                        description="A one-time code is sent to your Perfect World account email. Only the resulting session token is stored (valid for 30 days); no password is used. Accounts created with Google, Steam or PlayStation can use the 'Paste Token' tab."
                    />

                    <Form.Item
                        label="Email"
                        name="email"
                        rules={[
                            { required: true, message: "Please enter your Perfect World account email" },
                            { type: "email", message: "Please enter a valid email address" },
                        ]}
                    >
                        <Input prefix={<MailOutlined />} placeholder="user@example.com" autoComplete="email" />
                    </Form.Item>

                    <Form.Item style={{ marginBottom: 0, textAlign: "right" }}>
                        <Space>
                            <Button onClick={handleClose} disabled={wizardLoading}>
                                Cancel
                            </Button>
                            <Button type="primary" htmlType="submit" icon={<LoginOutlined />} loading={wizardLoading}>
                                Send Code
                            </Button>
                        </Space>
                    </Form.Item>
                </Space>
            </Form>
        );
    };

    const renderTokenTab = () => (
        <Form form={tokenForm} layout="vertical" onFinish={handleTokenFinish}>
            <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
                <Alert
                    type="info"
                    showIcon
                    title="Paste the website token"
                    description={
                        <span>
                            Sign in on the NTE Top-Up Center (nte.perfectworld.com), open DevTools → Application →
                            Local Storage → https://nte.perfectworld.com and copy the value of <Text code>_websdk_</Text>.
                        </span>
                    }
                />

                <Form.Item
                    label="_websdk_ value"
                    name="websdk"
                    rules={[
                        { required: true, message: "Please paste the _websdk_ value" },
                        {
                            validator: (_, value) => (!value || parseWebsdk(value)
                                ? Promise.resolve()
                                : Promise.reject(new Error('Expected {"uid":…,"token":"…"}'))),
                        },
                    ]}
                >
                    <Input.TextArea rows={3} placeholder='{"uid":1234567890,"token":"xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"}' />
                </Form.Item>

                {parsedWebsdk && (
                    <Text type="secondary">Perfect World account UID: {parsedWebsdk.uid}</Text>
                )}

                <Form.Item style={{ marginBottom: 0, textAlign: "right" }}>
                    <Space>
                        <Button onClick={handleClose}>Cancel</Button>
                        <Button
                            type="primary"
                            htmlType="submit"
                            loading={loading || tokenReloginLoading}
                            disabled={Boolean(rawWebsdk && !parsedWebsdk)}
                        >
                            Save
                        </Button>
                    </Space>
                </Form.Item>
            </Space>
        </Form>
    );

    return (
        <Modal
            title={reloginAccount ? `Re-login Account #${reloginAccount.id}` : "Add Perfect World Account"}
            open={open}
            width={600}
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
                                <span>Email Code</span>
                            </Space>
                        ),
                        children: renderLoginTab(),
                    },
                    {
                        key: "token",
                        label: (
                            <Space size={6}>
                                <KeyOutlined />
                                <span>Paste Token</span>
                            </Space>
                        ),
                        children: renderTokenTab(),
                    },
                ]}
            />
        </Modal>
    );
}
