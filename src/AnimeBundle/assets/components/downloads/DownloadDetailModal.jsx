import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import ExternalTitleLink from "@AnimeBundle/components/ExternalTitleLink";
import { TRACKER_CATALOG } from "@AnimeBundle/components/TrackerConstants";
import { formatDateTimeFromIso } from "@CoreBundle/format-utils";
import { App, Button, Descriptions, Modal, Space } from "antd";
import { DownloadOutlined, ExclamationCircleFilled } from "@ant-design/icons";
import React from "react";

export default function DownloadDetailModal({ open, setOpen, selectedId }) {
    const [loading, setLoading] = React.useState(true);
    const { modal } = App.useApp();
    const [data, setData] = React.useState(null);
    const api = useBackendApi();

    const onRetryOpen = React.useCallback(() => {
        if (!data) {
            return;
        }
        modal.confirm({
            icon: <ExclamationCircleFilled />,
            title: 'Are you sure you want to retry this download?',
            content: data.download.file,
            onOk: () => api
                .withLoadingMessage({
                    key: 'download-retry-loader',
                    loadingContent: 'Adding download...',
                    successContent: 'Download added successfully',
                })
                .downloads()
                .retry(data.download.id),
        });
    }, [data, api, modal]);

    const afterOpenChange = React.useCallback(opened => {
        if (!opened) {
            setLoading(true);
            setData(null);
            return;
        }
        setLoading(true);
        api
            .withErrorHandling()
            .downloads()
            .getId(selectedId)
            .then(res => {
                setData(res.data);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [selectedId, api]);

    const items = React.useMemo(() => {
        if (!data) {
            return [];
        }

        const baseItems = [
            {
                key: 1,
                label: 'Episode URL',
                children: data.download.episode_url,
                span: 10,
            },
            {
                key: 2,
                label: 'Download URL',
                children: data.download.download_url,
                span: 10,
            },
            {
                key: 3,
                label: 'Folder',
                children: data.download.folder,
                span: 4,
            },
            {
                key: 4,
                label: 'File',
                children: data.download.file,
                span: 6,
            },
            {
                key: 5,
                label: 'Episode',
                children: data.download.episode,
                span: 2,
            },
            {
                key: 6,
                label: 'State',
                children: data.download.state,
                span: 4,
            },
            {
                key: 7,
                label: 'Created',
                children: formatDateTimeFromIso(data.download.created),
                span: 4,
            },
            {
                key: 8,
                label: 'Started',
                children: formatDateTimeFromIso(data.download.started, "Not started"),
                span: 5,
            },
            {
                key: 9,
                label: 'Completed',
                children: formatDateTimeFromIso(data.download.completed, "Not completed"),
                span: 5,
            },
        ];

        // Dynamically iterates trackers map (es. myanimelist, anilist, etc.)
        const trackerItems = Object.values(data.download.trackers || {}).map((t) => {
            const config = TRACKER_CATALOG[t.tracker];
            const label = config?.label ?? t.tracker;
            const url = config ? config.buildAnimeUrl(t.tracker_id) : `#`;

            return {
                key: `tracker_${t.tracker}_${t.tracker_id}`,
                label: label,
                children: (
                    <ExternalTitleLink
                        id={t.tracker_id}
                        url={url}
                        fetchTitle={t.tracker_id ? () => api.tracker().animeTitle(t.tracker, t.tracker_id) : null}
                    />
                ),
                span: 5,
            };
        });

        return [...baseItems, ...trackerItems];
    }, [data, api]);

    return (
        <Modal
            title={
                <Space>
                    <span>Download details</span>
                    <Button
                        type="primary"
                        onClick={onRetryOpen}
                        icon={<DownloadOutlined />}
                        size="small"
                    />
                </Space>
            }
            footer={null}
            loading={loading}
            open={open}
            afterOpenChange={afterOpenChange}
            onCancel={() => setOpen(false)}
            destroyOnHidden
        >
            <Descriptions column={10} layout="vertical" items={items} />
        </Modal>
    );
}
