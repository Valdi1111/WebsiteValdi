import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { DeleteOutlined, ExclamationCircleFilled } from "@ant-design/icons";
import { App, Button, Descriptions, Modal, Space, Tooltip } from "antd";
import { formatDateTimeFromIso } from "@CoreBundle/format-utils";
import { Link } from "react-router-dom";
import React from "react";

export default function SeasonFolderDetailModal({ open, setOpen, selectedId, type }) {
    const [loading, setLoading] = React.useState(true);
    const [data, setData] = React.useState(null);

    const { modal } = App.useApp()
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
            .seasonsFolder()
            .getId(selectedId)
            .then(res => {
                setData(res.data);
                setLoading(false);
            });
    }, [selectedId]);

    const onDeleteOpen = React.useCallback(() => {
        if (!data) {
            return;
        }
        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: 'Are you sure you want to delete this season folder?',
            content: data.folder,
            onOk: () => api
                .withLoadingMessage({
                    key: 'season-folder-delete-loader',
                    loadingContent: 'Deleting season folder...',
                    successContent: 'Season folder deleted successfully',
                })
                .seasonsFolder()
                .delete(selectedId)
                .then(res => {
                    setOpen(false);
                }),
        });
    }, [data]);

    const items = React.useMemo(() => {
        if (!data) {
            return [];
        }
        return [
            {
                key: 1,
                label: 'MyAnimeList ID',
                children: <Link to={`https://myanimelist.net/anime/${data.id}`} target="_blank">
                    {data.id}
                </Link>,
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
    }, [data]);

    return <Modal
        title={<Space>
            <span>Season details</span>
            <Tooltip title={'Delete season folder'}>
                <Button shape="circle" color="danger" variant="outlined" icon={<DeleteOutlined/>}
                        onClick={() => onDeleteOpen()}
                />
            </Tooltip>
        </Space>}
        footer={null}
        loading={loading}
        open={open}
        afterOpenChange={afterOpenChange}
        onCancel={() => setOpen(false)}
        destroyOnHidden
    >
        <Descriptions column={4} layout={'vertical'} items={items}/>
    </Modal>;

}