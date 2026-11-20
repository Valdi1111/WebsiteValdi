import {
    CheckCircleOutlined,
    CloseCircleOutlined,
    InboxOutlined,
    UploadOutlined,
    WarningOutlined,
} from "@ant-design/icons";
import { Alert, Button, Col, Modal, Progress, Row, Space, Statistic, Typography, Upload } from "antd";
import React, { useEffect, useState } from "react";

const { Dragger } = Upload;
const { Text } = Typography;

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
    const [selectedFile, setSelectedFile] = useState(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState(null);
    const [trackedOperationId, setTrackedOperationId] = useState(null);

    useEffect(() => {
        if (!open) {
            setSelectedFile(null);
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

    const handleSubmit = () => {
        if (!selectedFile || !importer || !onUpload) {
            return;
        }

        setUploading(true);
        setUploadError(null);

        Promise.resolve(onUpload(importer.key, selectedFile))
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
                disabled={!selectedFile}
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
                        multiple={false}
                        maxCount={1}
                        accept={importer?.accept || undefined}
                        fileList={
                            selectedFile
                                ? [
                                      {
                                          uid: selectedFile.uid || selectedFile.name,
                                          name: selectedFile.name,
                                          status: "done",
                                          size: selectedFile.size,
                                      },
                                  ]
                                : []
                        }
                        beforeUpload={(file) => {
                            setSelectedFile(file);
                            setUploadError(null);
                            return false;
                        }}
                        onRemove={() => {
                            setSelectedFile(null);
                            setUploadError(null);
                        }}
                        disabled={uploading}
                    >
                        <p className="ant-upload-drag-icon">
                            <InboxOutlined />
                        </p>
                        <p className="ant-upload-text">
                            Click or drag backup file to this area
                        </p>
                        <p className="ant-upload-hint">
                            Accepted format: {importer?.accept || "any"} (max 25 MB)
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
                        <Col span={8}>
                            <Statistic
                                title="Imported"
                                value={trackedOperation.imported ?? 0}
                                styles={{ content: { color: "#52c41a" } }}
                            />
                        </Col>
                        <Col span={8}>
                            <Statistic
                                title="Skipped"
                                value={trackedOperation.skipped ?? 0}
                            />
                        </Col>
                    </Row>

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
