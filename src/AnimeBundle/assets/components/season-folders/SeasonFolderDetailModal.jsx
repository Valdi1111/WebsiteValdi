import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { useTracker } from "@AnimeBundle/components/TrackerContext";
import ExternalTitleLink from "@AnimeBundle/components/ExternalTitleLink";
import { DeleteOutlined, ExclamationCircleFilled } from "@ant-design/icons";
import { App, Button, Descriptions, Modal, Space, Tooltip } from "antd";
import { formatDateTimeFromIso } from "@CoreBundle/format-utils";
import React from "react";

export default function SeasonFolderDetailModal({ open, setOpen, selectedId }) {
    const [loading, setLoading] = React.useState(true);
    const [data, setData] = React.useState(null);
    const { tracker, trackerConfig } = useTracker();

    const { modal } = App.useApp();
    const api = useBackendApi();

    const afterOpenChange = React.useCallback(opened => {
        if (!opened) {
            setLoading(true);
            setData(null);
            return;
        }
        setLoading(true);
        api
            .withErrorHandling()
            .seasonFolders()
            .getId(tracker, selectedId)
            .then(res => {
                setData(res.data);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [selectedId, tracker, api]);

    const onDeleteOpen = React.useCallback(() => {
        if (!data) {
            return;
        }
        modal.confirm({
            icon: <ExclamationCircleFilled />,
            title: 'Are you sure you want to delete this season folder?',
            content: data.folder,
            onOk: () => api
                .withLoadingMessage({
                    key: 'season-folder-delete-loader',
                    loadingContent: 'Deleting season folder...',
                    successContent: 'Season folder deleted successfully',
                })
                .seasonFolders()
                .delete(tracker, selectedId)
                .then(() => setOpen(false)),
        });
    }, [data, api, modal, tracker, selectedId, setOpen]);

    const items = React.useMemo(() => {
        if (!data) {
            return [];
        }
        return [
            {
                key: 1,
                label: trackerConfig.label,
                children: (
                    <ExternalTitleLink
                        id={data.id}
                        url={trackerConfig.buildAnimeUrl(data.id)}
                        fetchTitle={() => api.tracker().animeTitle(tracker, data.id)}
                    />
                ),
                span: 2,
            },
            {
                key: 2,
                label: 'Created',
                children: formatDateTimeFromIso(data.created),
                span: 2,
            },
            {
                key: 3,
                label: 'Folder',
                children: data.folder,
                span: 4,
            },
        ];
    }, [data, tracker, trackerConfig, api]);

    return (
        <Modal
            title={
                <Space>
                    <span>Season details</span>
                    <Tooltip title="Delete season folder">
                        <Button
                            shape="circle"
                            color="danger"
                            variant="outlined"
                            icon={<DeleteOutlined />}
                            onClick={() => onDeleteOpen()}
                        />
                    </Tooltip>
                </Space>
            }
            footer={null}
            loading={loading}
            open={open}
            afterOpenChange={afterOpenChange}
            onCancel={() => setOpen(false)}
            destroyOnHidden
        >
            <Descriptions column={4} layout="vertical" items={items} />
        </Modal>
    );
}
