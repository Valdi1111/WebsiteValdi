import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { formatDateTimeFromIso } from "@CoreBundle/format-utils";
import { Descriptions, Modal, Space } from "antd";
import { DownloadOutlined } from "@ant-design/icons";
import { Link } from "react-router-dom";
import React from "react";

export default function DownloadDetailModal({ open, setOpen, selectedId }) {
    const [loading, setLoading] = React.useState(true);
    const [confirmLoading, setConfirmLoading] = React.useState(false);

    const [data, setData] = React.useState(null);
    const api = useBackendApi();

    // TODO creare il retry
    const onRetry = React.useCallback(() => {
        if (!data) {
            return;
        }
        setConfirmLoading(true);
        api
            .withLoadingMessage({
                key: 'download-retry-loader',
                loadingContent: 'Adding download...',
                successContent: 'Download added successfully',
            })
            .downloads()
            .retry(data.id)
            .then(res => {
                setOpen(false);
            })
            .finally(() => setConfirmLoading(false));
    }, [data]);

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
            });
    }, [selectedId]);

    const items = React.useMemo(() => {
        if (!data) {
            return [];
        }
        return [
            {
                key: 1,
                label: 'Episode URL',
                children: data.episode_url,
                span: 10,
            },
            {
                key: 2,
                label: 'Download URL',
                children: data.download_url,
                span: 10,
            },
            {
                key: 3,
                label: 'Folder',
                children: data.folder,
                span: 4,
            },
            {
                key: 4,
                label: 'File',
                children: data.file,
                span: 6,
            },
            {
                key: 5,
                label: 'Episode',
                children: data.episode,
                span: 2,
            },
            {
                key: 6,
                label: 'State',
                children: data.state,
                span: 4,
            },
            {
                key: 7,
                label: 'Created',
                children: formatDateTimeFromIso(data.created),
                span: 4,
            },
            {
                key: 8,
                label: 'Started',
                children: formatDateTimeFromIso(data.started, "Not started"),
                span: 5,
            },
            {
                key: 9,
                label: 'Completed',
                children: formatDateTimeFromIso(data.completed, "Not completed"),
                span: 5,
            },
            {
                key: 10,
                label: 'MyAnimeList ID',
                children: <Link to={`https://myanimelist.net/anime/${data.mal_id}`} target="_blank">
                    {data.mal_id}
                </Link>,
                span: 5,
            },
            {
                key: 11,
                label: 'AniList ID',
                children: <Link to={`https://anilist.co/anime/${data.al_id}`} target="_blank">
                    {data.al_id}
                </Link>,
                span: 5,
            },
        ];
    }, [data]);

    return <Modal
        title={<Space>
            <span>Download details</span>
            <Link to={"downloadIdRefresh(id)"} target="_blank" className="me-2">
                <DownloadOutlined/>
            </Link>
        </Space>}
        footer={null}
        loading={loading}
        open={open}
        afterOpenChange={afterOpenChange}
        onCancel={() => setOpen(false)}
        destroyOnHidden
    >
        <Descriptions column={10} layout={'vertical'} items={items}/>
    </Modal>;

}