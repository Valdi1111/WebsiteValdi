import { CheckCircleOutlined, CloseCircleOutlined, KeyOutlined, InfoCircleOutlined } from "@ant-design/icons";
import { Modal, Form, Input, Space, Button, Descriptions, Tag, Alert } from "antd";
import React, { useMemo } from "react";

export default function AddAccountModal({ open, onCancel, onSubmit, loading }) {
    const [form] = Form.useForm();
    const rawInput = Form.useWatch("account_token", form);

    const parsedToken = useMemo(() => {
        if (!rawInput || typeof rawInput !== "string") {
            return { token: "", isValid: false };
        }

        const trimmed = rawInput.trim();
        // Check if user pasted a URL or query string containing token= or account_token=
        const match = trimmed.match(/(?:token|account_token)=([^&;\s]+)/i);
        const extracted = match ? decodeURIComponent(match[1]) : trimmed;

        return {
            token: extracted,
            isValid: extracted.length > 5,
        };
    }, [rawInput]);

    const handleClose = () => {
        form.resetFields();
        onCancel();
    };

    const handleFinish = (values) => {
        const tokenToSubmit = parsedToken.isValid ? parsedToken.token : values.account_token?.trim();
        onSubmit({ account_token: tokenToSubmit }, () => form.resetFields());
    };

    return (
        <Modal
            title="Add Gryphline Account"
            open={open}
            width={620}
            onCancel={handleClose}
            footer={null}
        >
            <Form form={form} layout="vertical" onFinish={handleFinish}>
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
        </Modal>
    );
}
