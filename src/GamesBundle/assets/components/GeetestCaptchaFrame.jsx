import { ReloadOutlined, SafetyCertificateOutlined } from "@ant-design/icons";
import { Alert, Button, Space, Spin } from "antd";
import React, { useEffect, useRef, useState } from "react";

export default function GeetestCaptchaFrame({
    frameUrl,
    onSuccess,
    onError,
    submitting = false,
}) {
    const iframeRef = useRef(null);
    const [frameReady, setFrameReady] = useState(false);
    const [closedOrErrored, setClosedOrErrored] = useState(false);
    const [iframeKey, setIframeKey] = useState(0);

    useEffect(() => {
        setFrameReady(false);
        setClosedOrErrored(false);
    }, [frameUrl, iframeKey]);

    useEffect(() => {
        const handleMessage = (event) => {
            if (event.origin !== window.location.origin) {
                return;
            }
            if (!iframeRef.current || event.source !== iframeRef.current.contentWindow) {
                return;
            }

            const data = event.data;
            if (!data || typeof data !== "object") {
                return;
            }

            switch (data.type) {
                case "GEETEST_READY":
                    setFrameReady(true);
                    setClosedOrErrored(false);
                    break;
                case "GEETEST_SUCCESS":
                    if (data.payload) {
                        onSuccess?.(data.payload);
                    }
                    break;
                case "GEETEST_CLOSE":
                    setClosedOrErrored(true);
                    break;
                case "GEETEST_ERROR":
                    setClosedOrErrored(true);
                    onError?.(data.payload);
                    break;
                default:
                    break;
            }
        };

        window.addEventListener("message", handleMessage);
        return () => window.removeEventListener("message", handleMessage);
    }, [onSuccess, onError]);

    const handleReopen = () => {
        setClosedOrErrored(false);
        if (iframeRef.current?.contentWindow) {
            iframeRef.current.contentWindow.postMessage({ type: "GEETEST_OPEN" }, window.location.origin);
        } else {
            setIframeKey((prev) => prev + 1);
        }
    };

    const handleReloadFrame = () => {
        setIframeKey((prev) => prev + 1);
    };

    return (
        <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
            <Alert
                type="info"
                showIcon
                icon={<SafetyCertificateOutlined />}
                title="Security Verification Required"
                description="Please complete the interactive puzzle in the frame below to continue signing in."
            />

            <div
                style={{
                    position: "relative",
                    width: "100%",
                    minHeight: 360,
                    border: "1px solid rgba(140, 140, 140, 0.25)",
                    borderRadius: 8,
                    overflow: "hidden",
                    background: "rgba(0, 0, 0, 0.02)",
                }}
            >
                {(!frameReady || submitting) && (
                    <div
                        style={{
                            position: "absolute",
                            inset: 0,
                            display: "flex",
                            alignItems: "center",
                            justifyContent: "center",
                            background: "rgba(0, 0, 0, 0.04)",
                            zIndex: 2,
                        }}
                    >
                        <Spin description={submitting ? "Verifying captcha..." : "Loading security challenge..."} />
                    </div>
                )}

                <iframe
                    key={iframeKey}
                    ref={iframeRef}
                    src={frameUrl}
                    title="GeeTest Security Verification"
                    sandbox="allow-scripts allow-same-origin"
                    style={{
                        width: "100%",
                        height: 360,
                        border: "none",
                        display: "block",
                    }}
                />
            </div>

            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                <Button size="small" icon={<ReloadOutlined />} onClick={handleReloadFrame} disabled={submitting}>
                    Reload Challenge
                </Button>
                {closedOrErrored && (
                    <Button type="primary" size="small" onClick={handleReopen} disabled={submitting}>
                        Show Captcha / Retry
                    </Button>
                )}
            </div>
        </Space>
    );
}
