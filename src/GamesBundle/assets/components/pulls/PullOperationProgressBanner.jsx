import {
    CheckCircleOutlined,
    ClockCircleOutlined,
    CloseCircleOutlined,
    CloseOutlined,
    SyncOutlined,
    WarningOutlined,
} from "@ant-design/icons";
import { Button, Card, Progress, Space, Tag, Tooltip, Typography } from "antd";
import React from "react";

const { Text } = Typography;

const PHASE_LABELS = {
    queued: "Queued in background...",
    parsing: "Parsing backup & resolving item catalog...",
    importing: "Importing pulls...",
    syncing: "Syncing banners...",
    completed: "Completed",
    failed: "Failed",
};

export default function PullOperationProgressBanner({ operation, onDismiss }) {
    if (!operation) {
        return null;
    }

    const isBackup = operation.kind === "backup_import";
    const status = operation.status || "queued";
    const isFinished = status === "completed" || status === "failed";
    const progressStatus =
        status === "failed" ? "exception" : status === "completed" ? "success" : "active";

    // Syncs always track banners; backup imports do when the importer reports them (multi-file imports)
    const bannersList =
        operation.banners && typeof operation.banners === "object"
            ? Object.values(operation.banners)
            : [];

    const warnings = Array.isArray(operation.warnings) ? operation.warnings : [];

    const renderStatusIcon = () => {
        if (status === "completed") {
            return <CheckCircleOutlined style={{ color: "#52c41a" }} />;
        }
        if (status === "failed") {
            return <CloseCircleOutlined style={{ color: "#ff4d4f" }} />;
        }
        return <SyncOutlined spin style={{ color: "#1677ff" }} />;
    };

    const renderBannerTag = (banner) => {
        let color = "default";
        let icon = <ClockCircleOutlined />;
        if (banner.status === "running") {
            color = "processing";
            icon = <SyncOutlined spin />;
        } else if (banner.status === "completed") {
            color = "success";
            icon = <CheckCircleOutlined />;
        } else if (banner.status === "failed") {
            color = "error";
            icon = <CloseCircleOutlined />;
        }

        const extraParts = [];
        if (banner.pages > 0 && banner.status === "running") {
            extraParts.push(`p.${banner.pages}`);
        }
        if (isBackup && banner.total > 0 && banner.status === "running") {
            extraParts.push(`${banner.processed ?? 0}/${banner.total}`);
        }
        if (banner.imported > 0) {
            extraParts.push(`+${banner.imported}`);
        }
        if (banner.updated > 0) {
            extraParts.push(`${banner.updated} updated`);
        }
        if (banner.promoted > 0) {
            extraParts.push(`${banner.promoted} linked`);
        }

        const label =
            extraParts.length > 0
                ? `${banner.name} (${extraParts.join(", ")})`
                : banner.name;

        const tag = (
            <Tag key={banner.gacha_type} color={color} icon={icon} style={{ margin: 0 }}>
                {label}
            </Tag>
        );

        return banner.error ? (
            <Tooltip key={banner.gacha_type} title={banner.error}>
                {tag}
            </Tooltip>
        ) : (
            tag
        );
    };

    return (
        <Card
            size="small"
            style={{
                marginBottom: 16,
                borderColor:
                    status === "failed"
                        ? "#ffccc7"
                        : status === "completed"
                        ? "#b7eb8f"
                        : "#91caff",
            }}
        >
            <div
                style={{
                    display: "flex",
                    justifyContent: "space-between",
                    alignItems: "center",
                    flexWrap: "wrap",
                    gap: 8,
                    marginBottom: 6,
                }}
            >
                <Space size={8} wrap>
                    {renderStatusIcon()}
                    <Text strong>{operation.label || "Pulls Operation"}</Text>
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        {PHASE_LABELS[operation.phase] || operation.phase}
                    </Text>
                </Space>

                <Space size={6} wrap>
                    {isBackup ? (
                        <>
                            <Tag color="blue" style={{ margin: 0 }}>
                                {operation.processed ?? 0} / {operation.total ?? 0}
                            </Tag>
                            <Tag color="green" style={{ margin: 0 }}>
                                Imported: {operation.imported ?? 0}
                            </Tag>
                            {(operation.updated ?? 0) > 0 && (
                                <Tag color="geekblue" style={{ margin: 0 }}>
                                    Updated: {operation.updated}
                                </Tag>
                            )}
                            <Tag style={{ margin: 0 }}>
                                Skipped: {operation.skipped ?? 0}
                            </Tag>
                        </>
                    ) : (
                        <>
                            <Tag color="blue" style={{ margin: 0 }}>
                                Banners: {operation.processed ?? 0} / {operation.total ?? 0}
                            </Tag>
                            <Tag color="green" style={{ margin: 0 }}>
                                New: {operation.imported ?? 0}
                            </Tag>
                            {(operation.promoted ?? 0) > 0 && (
                                <Tag color="purple" style={{ margin: 0 }}>
                                    Linked: {operation.promoted}
                                </Tag>
                            )}
                        </>
                    )}

                    {warnings.length > 0 && (
                        <Tooltip title={warnings.slice(0, 5).join(" • ")}>
                            <Tag color="warning" icon={<WarningOutlined />} style={{ margin: 0 }}>
                                {warnings.length} warning{warnings.length > 1 ? "s" : ""}
                            </Tag>
                        </Tooltip>
                    )}

                    {isFinished && onDismiss && (
                        <Button
                            type="text"
                            size="small"
                            icon={<CloseOutlined />}
                            onClick={onDismiss}
                            aria-label="Dismiss status"
                        />
                    )}
                </Space>
            </div>

            <Progress
                percent={operation.percent ?? 0}
                status={progressStatus}
                size="small"
                style={{ marginBottom: bannersList.length > 0 || operation.error ? 8 : 0 }}
            />

            {bannersList.length > 0 && (
                <Space size={[6, 6]} wrap>
                    {bannersList.map(renderBannerTag)}
                </Space>
            )}

            {operation.error && (
                <Text type="danger" style={{ display: "block", marginTop: 4, fontSize: 12 }}>
                    {operation.error}
                </Text>
            )}
        </Card>
    );
}
