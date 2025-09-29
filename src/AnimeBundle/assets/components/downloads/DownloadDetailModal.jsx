import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { formatDateTimeFromIso } from "@CoreBundle/utils";
import { App, Descriptions, Modal, Space } from "antd";
import { DownloadOutlined } from "@ant-design/icons";
import { Link } from "react-router-dom";
import React from "react";

export default function DownloadDetailModal({ open, setOpen, selectedId }) {
    const [loading, setLoading] = React.useState(true);
    const [confirmLoading, setConfirmLoading] = React.useState(false);

    const [downloadData, setDownloadData] = React.useState({});
    const { message } = App.useApp();
    const api = useBackendApi();

    // TODO creare il retry
    const onSubmit = React.useCallback(data => {
        setConfirmLoading(true);
        message.open({
            key: 'download-add-loader',
            type: 'loading',
            content: 'Adding download...',
            duration: 0,
        });
        api.downloads.add(data).then(
            res => {
                message.open({
                    key: 'download-add-loader',
                    type: 'success',
                    content: 'Download added successfully',
                    duration: 2.5,
                });
                setOpen(false);
            },
            err => {
                message.destroy('download-add-loader');
                console.error(err);
            }
        ).finally(() => {
            setConfirmLoading(false);
        });
    }, []);

    const afterOpenChange = React.useCallback(opened => {
        if (!opened) {
            setLoading(true);
            setDownloadData({});
            return;
        }
        setLoading(true);
        api.downloads.getId(selectedId).then(
            res => {
                setDownloadData(res.data);
                setLoading(false);
            },
            err => console.error(err)
        );
    }, [selectedId]);

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
        <Descriptions column={10} layout={'vertical'} items={[
            {
                key: 1,
                label: 'Episode URL',
                children: downloadData.episode_url,
                span: 10,
            },
            {
                key: 2,
                label: 'Download URL',
                children: downloadData.download_url,
                span: 10,
            },
            {
                key: 3,
                label: 'Folder',
                children: downloadData.folder,
                span: 4,
            },
            {
                key: 4,
                label: 'File',
                children: downloadData.file,
                span: 6,
            },
            {
                key: 5,
                label: 'Episode',
                children: downloadData.episode,
                span: 2,
            },
            {
                key: 6,
                label: 'State',
                children: downloadData.state,
                span: 4,
            },
            {
                key: 7,
                label: 'Created',
                children: formatDateTimeFromIso(downloadData.created),
                span: 4,
            },
            {
                key: 8,
                label: 'Started',
                children: formatDateTimeFromIso(downloadData.started, "Not started"),
                span: 5,
            },
            {
                key: 9,
                label: 'Completed',
                children: formatDateTimeFromIso(downloadData.completed, "Not completed"),
                span: 5,
            },
            {
                key: 10,
                label: 'MyAnimeList ID',
                children: <Link to={`https://myanimelist.net/anime/${downloadData.mal_id}`} target="_blank">
                    {downloadData.mal_id}
                </Link>,
                span: 5,
            },
            {
                key: 11,
                label: 'AniList ID',
                children: <Link to={`https://anilist.co/anime/${downloadData.al_id}`} target="_blank">
                    {downloadData.al_id}
                </Link>,
                span: 5,
            },
        ]}/>
    </Modal>;

}