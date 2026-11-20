import GeetestCaptchaFrame from "@GamesBundle/components/GeetestCaptchaFrame";
import { Button, Space } from "antd";
import React from "react";

/**
 * GeeTest captcha step of the login wizard (see useLoginWizard).
 */
export default function CaptchaStep({ frameUrl, onSuccess, submitting, onBack, onCancel }) {
    return (
        <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
            <GeetestCaptchaFrame
                frameUrl={frameUrl}
                onSuccess={onSuccess}
                submitting={submitting}
            />
            <div style={{ textAlign: "right" }}>
                <Space>
                    <Button onClick={onBack} disabled={submitting}>
                        Back to Credentials
                    </Button>
                    <Button onClick={onCancel} disabled={submitting}>
                        Cancel
                    </Button>
                </Space>
            </div>
        </Space>
    );
}
