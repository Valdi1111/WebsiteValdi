import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { HistoryOutlined } from "@ant-design/icons";
import { Alert, Button, Modal, Space, Typography } from "antd";
import React, { useState } from "react";

const { Paragraph, Text } = Typography;

/**
 * Starts the import of the official S-rank history (Zenless Zone Zero: the "Signal Search" statistics of the HoYoLAB
 * battle record) into the missing pulls of the profile. The import runs in the background and is tracked like the
 * other pull operations (PullOperationProgressBanner).
 */
export default function SRankHistoryImportModal({ open, profile, accountId, profileId, onClose, onImportStarted }) {
    const [loading, setLoading] = useState(false);
    const api = useBackendApi();

    const resolvedProfileId = profile?.id ?? profileId;

    const handleSubmit = () => {
        setLoading(true);
        api
            .withLoadingMessage({
                key: "import-s-rank-history",
                loadingContent: "Queueing the S-rank history import...",
                successContent: "Import queued: the official history is read in the background.",
            })
            .gameProfiles()
            .importSRankHistory(accountId, resolvedProfileId)
            .then((res) => {
                onImportStarted?.(res?.data?.operation || null);
                onClose();
            })
            .catch(() => {
                // Error already displayed by withLoadingMessage
            })
            .finally(() => setLoading(false));
    };

    return (
        <Modal
            title={
                <Space>
                    <HistoryOutlined style={{ color: "#1890ff" }} />
                    <span>Import S-rank history from HoYoLAB{profile?.nickname || profile?.game_name ? ` - ${profile?.nickname || profile?.game_name}` : ""}</span>
                </Space>
            }
            open={open}
            onCancel={onClose}
            width={600}
            footer={[
                <Button key="cancel" onClick={onClose} disabled={loading}>
                    Cancel
                </Button>,
                <Button key="submit" type="primary" loading={loading} onClick={handleSubmit}>
                    Start Import
                </Button>,
            ]}
        >
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                title="What the official history adds"
                description="HoYoLAB keeps the S-ranks of every banner since the start of the account, with their pity and date, beyond the 6 months of the in-game history. Only the S-ranks are listed: the pulls between them are added as unknown pulls."
            />

            <Paragraph>
                <ul style={{ paddingInlineStart: 20, marginBottom: 0 }}>
                    <li>S-ranks missing from your history are added with a <Text strong>HoYoLAB</Text> tag; their pity and date cannot be edited.</li>
                    <li>S-ranks already stored keep their date and get the official pity and rate-up flag.</li>
                    <li>Pities you corrected and S-ranks you added by hand are replaced by the official values: every change is listed in the warnings.</li>
                    <li>A banner that does not match the official history is left unchanged, with the reason.</li>
                    <li>Running it again changes nothing unless new pulls were made.</li>
                </ul>
            </Paragraph>

            <Text type="secondary">
                It reads about 15 pages of HoYoLAB with the cookie of the account, in the background: you can close this page.
            </Text>
        </Modal>
    );
}
