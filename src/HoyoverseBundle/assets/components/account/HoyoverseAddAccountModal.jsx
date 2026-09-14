import { CheckCircleOutlined, CloseCircleOutlined, KeyOutlined } from "@ant-design/icons";
import { Modal, Form, Input, Space, Button, Descriptions, Tag, Alert } from "antd";
import React, { useMemo } from "react";

export default function HoyoverseAddAccountModal({ open, onCancel, onSubmit, loading }) {
    const [form] = Form.useForm();
    const rawCookie = Form.useWatch("cookie", form);

    const parsedTokens = useMemo(() => {
        if (!rawCookie || typeof rawCookie !== "string") {
            return { tokens: {}, isValid: false, canRedeemCodes: false };
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

        return { tokens, isValid, canRedeemCodes };
    }, [rawCookie]);

    const handleClose = () => {
        form.resetFields();
        onCancel();
    };

    const handleFinish = (values) => {
        onSubmit(values, () => form.resetFields());
    };

    return (
        <Modal
            title="Add HoYoverse Account"
            open={open}
            width={620}
            onCancel={handleClose}
            footer={null}
        >
            <Form form={form} layout="vertical" onFinish={handleFinish}>
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

                        <div style={{ marginTop: 12 }}>
                            {!parsedTokens.isValid ? (
                                <Alert
                                    type="error"
                                    showIcon
                                    message="Invalid Cookie"
                                    description="Missing required base credentials (ltoken_v2, ltuid_v2, or ltmid_v2). The account cannot be saved without them."
                                />
                            ) : parsedTokens.canRedeemCodes ? (
                                <Alert
                                    type="success"
                                    showIcon
                                    message="Code Redemption Enabled"
                                    description="All 3 redemption tokens (cookie_token_v2, account_mid_v2, and account_id_v2) are present. Automatic promo code redemption will be functional."
                                />
                            ) : (
                                <Alert
                                    type="warning"
                                    showIcon
                                    message="Code Redemption Disabled"
                                    description="Missing one or more redemption tokens (cookie_token_v2, account_mid_v2, account_id_v2). Daily check-in and stamina checks will work, but automatic promo code redemption will not be supported."
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
                            disabled={Boolean(rawCookie && !parsedTokens.isValid)}
                        >
                            Save
                        </Button>
                    </Space>
                </Form.Item>
            </Form>
        </Modal>
    );
}