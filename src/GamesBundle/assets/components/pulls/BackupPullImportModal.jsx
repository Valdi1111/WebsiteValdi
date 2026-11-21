import {
    CheckCircleOutlined,
    CloseCircleOutlined,
    InboxOutlined,
    UploadOutlined,
    WarningOutlined,
} from "@ant-design/icons";
import { Alert, Button, Col, Modal, Progress, Row, Space, Statistic, Tag, Typography, Upload } from "antd";
import React, { useEffect, useState } from "react";

const { Dragger } = Upload;
const { Text } = Typography;

const BANNER_STATUS_COLORS = {
    queued: "default",
    running: "processing",
    completed: "success",
    failed: "error",
};

const PHASE_LABELS = {
    queued: "Queued in background worker...",
    parsing: "Parsing backup & resolving item catalog...",
    importing: "Importing pulls into database...",
    completed: "Import completed!",
    failed: "Import failed",
};

export default function BackupPullImportModal({
    open,
    importer,
    profile,
    activeOperation,
    onUpload,
    onClose,
    onOperationStarted,
}) {
    // Importers with "multiple" metadata accept several files in one upload (e.g. one file per banner)
    const isMultiple = Boolean(importer?.multiple);
    const maxFiles = isMultiple ? importer?.max_files || 10 : 1;
    const [selectedFiles, setSelectedFiles] = useState([]);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState(null);
    const [trackedOperationId, setTrackedOperationId] = useState(null);

    useEffect(() => {
        if (!open) {
            setSelectedFiles([]);
            setUploading(false);
            setUploadError(null);
            setTrackedOperationId(null);
        }
    }, [open, importer?.key]);

    const trackedOperation =
        trackedOperationId && activeOperation?.id === trackedOperationId
            ? activeOperation
            : null;

    const isRunning =
        trackedOperation &&
        (trackedOperation.status === "queued" || trackedOperation.status === "running");
    const isCompleted = trackedOperation?.status === "completed";
    const isFailed = trackedOperation?.status === "failed";
    const hasUpdated = (trackedOperation?.updated ?? 0) > 0;
    const bannerProgress = trackedOperation?.banners && typeof trackedOperation.banners === "object"
        ? Object.values(trackedOperation.banners)
        : [];

    const handleSubmit = () => {
        if (selectedFiles.length === 0 || !importer || !onUpload) {
            return;
        }

        setUploading(true);
        setUploadError(null);

        Promise.resolve(onUpload(importer.key, isMultiple ? selectedFiles : selectedFiles[0]))
            .then((res) => {
                const op = res?.data?.operation || null;
                if (op?.id) {
                    setTrackedOperationId(op.id);
                    onOperationStarted?.(op);
                }
            })
            .catch((err) => {
                const detail =
                    err?.response?.data?.detail ||
                    err?.response?.data?.message ||
                    err?.message ||
                    "Failed to upload backup file.";
                setUploadError(detail);
            })
            .finally(() => {
                setUploading(false);
            });
    };

    const renderFooter = () => {
        if (isRunning) {
            return [
                <Button key="bg" type="primary" onClick={onClose}>
                    Run in Background
                </Button>,
            ];
        }

        if (isCompleted || isFailed) {
            return [
                <Button key="close" type="primary" onClick={onClose}>
                    Close
                </Button>,
            ];
        }

        return [
            <Button key="cancel" onClick={onClose} disabled={uploading}>
                Cancel
            </Button>,
            <Button
                key="submit"
                type="primary"
                icon={<UploadOutlined />}
                loading={uploading}
                disabled={selectedFiles.length === 0}
                onClick={handleSubmit}
            >
                Validate & Import
            </Button>,
        ];
    };

    return (
        <Modal
            title={
                <Space>
                    <UploadOutlined style={{ color: "#1677ff" }} />
                    <span>
                        Import from {importer?.label || "Backup"}
                        {profile?.nickname ? ` — ${profile.nickname}` : ""}
                    </span>
                </Space>
            }
            open={open}
            onCancel={onClose}
            width={580}
            footer={renderFooter()}
        >
            {!trackedOperation ? (
                <>
                    <Alert
                        type="info"
                        showIcon
                        style={{ marginBottom: 16 }}
                        title="How to export your backup"
                        description={
                            <>
                                <div>{importer?.description}</div>
                                <div style={{ marginTop: 6 }}>
                                    Pulls will be imported directly into{" "}
                                    <strong>
                                        {profile?.nickname} ({profile?.game_name})
                                    </strong>
                                    . Already stored pulls are automatically skipped without duplicates.
                                </div>
                            </>
                        }
                    />

                    {uploadError && (
                        <Alert
                            type="error"
                            showIcon
                            style={{ marginBottom: 16 }}
                            title="Validation / Upload Error"
                            description={uploadError}
                        />
                    )}

                    <Dragger
                        multiple={isMultiple}
                        maxCount={maxFiles}
                        accept={importer?.accept || undefined}
                        fileList={selectedFiles.map((file) => ({
                            uid: file.uid || file.name,
                            name: file.name,
                            status: "done",
                            size: file.size,
                        }))}
                        beforeUpload={(file) => {
                            // Single-file importers replace the selection, multi-file ones append (up to maxFiles)
                            setSelectedFiles((prev) => (isMultiple ? [...prev, file].slice(-maxFiles) : [file]));
                            setUploadError(null);
                            return false;
                        }}
                        onRemove={(removed) => {
                            setSelectedFiles((prev) => prev.filter((file) => (file.uid || file.name) !== removed.uid));
                            setUploadError(null);
                        }}
                        disabled={uploading}
                    >
                        <p className="ant-upload-drag-icon">
                            <InboxOutlined />
                        </p>
                        <p className="ant-upload-text">
                            {isMultiple
                                ? `Click or drag up to ${maxFiles} backup files to this area`
                                : "Click or drag backup file to this area"}
                        </p>
                        <p className="ant-upload-hint">
                            Accepted format: {importer?.accept || "any"} (max 25 MB{isMultiple ? " each" : ""})
                        </p>
                    </Dragger>
                </>
            ) : (
                <Space orientation="vertical" size="middle" style={{ width: "100%" }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                        {isCompleted && (
                            <CheckCircleOutlined style={{ color: "#52c41a", fontSize: 18 }} />
                        )}
                        {isFailed && (
                            <CloseCircleOutlined style={{ color: "#ff4d4f", fontSize: 18 }} />
                        )}
                        <Text strong>
                            {PHASE_LABELS[trackedOperation.phase] || trackedOperation.phase}
                        </Text>
                    </div>

                    <Progress
                        percent={trackedOperation.percent ?? 0}
                        status={
                            isFailed ? "exception" : isCompleted ? "success" : "active"
                        }
                    />

                    <Row gutter={16}>
                        <Col span={8}>
                            <Statistic
                                title="Processed"
                                value={trackedOperation.processed ?? 0}
                                suffix={`/ ${trackedOperation.total ?? 0}`}
                            />
                        </Col>
                        <Col span={hasUpdated ? 5 : 8}>
                            <Statistic
                                title="Imported"
                                value={trackedOperation.imported ?? 0}
                                styles={{ content: { color: "#52c41a" } }}
                            />
                        </Col>
                        {hasUpdated && (
                            <Col span={6}>
                                <Statistic
                                    title="Updated"
                                    value={trackedOperation.updated}
                                    styles={{ content: { color: "#1677ff" } }}
                                />
                            </Col>
                        )}
                        <Col span={hasUpdated ? 5 : 8}>
                            <Statistic
                                title="Skipped"
                                value={trackedOperation.skipped ?? 0}
                            />
                        </Col>
                    </Row>

                    {bannerProgress.length > 0 && (
                        <Space orientation="vertical" size={4} style={{ width: "100%" }}>
                            {bannerProgress.map((banner) => (
                                <div
                                    key={banner.gacha_type}
                                    style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}
                                >
                                    <Text>{banner.name}</Text>
                                    <Space size={4} wrap>
                                        <Tag color={BANNER_STATUS_COLORS[banner.status] || "default"} style={{ margin: 0 }}>
                                            {banner.processed ?? 0}/{banner.total ?? 0}
                                        </Tag>
                                        {banner.imported > 0 && <Tag color="green" style={{ margin: 0 }}>+{banner.imported}</Tag>}
                                        {banner.updated > 0 && <Tag color="blue" style={{ margin: 0 }}>{banner.updated} updated</Tag>}
                                    </Space>
                                </div>
                            ))}
                        </Space>
                    )}

                    {trackedOperation.error && (
                        <Alert
                            type="error"
                            showIcon
                            title="Import failed"
                            description={trackedOperation.error}
                        />
                    )}

                    {Array.isArray(trackedOperation.warnings) &&
                        trackedOperation.warnings.length > 0 && (
                            <Alert
                                type="warning"
                                showIcon
                                icon={<WarningOutlined />}
                                title={`${trackedOperation.warnings.length} warning(s)`}
                                description={
                                    <ul style={{ margin: 0, paddingLeft: 18 }}>
                                        {trackedOperation.warnings.slice(0, 5).map((w, idx) => (
                                            <li key={idx}>{w}</li>
                                        ))}
                                    </ul>
                                }
                            />
                        )}
                </Space>
            )}
        </Modal>
    );
}
