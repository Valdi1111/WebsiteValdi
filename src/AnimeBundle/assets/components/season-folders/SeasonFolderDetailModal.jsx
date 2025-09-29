import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { DeleteOutlined, ExclamationCircleFilled } from "@ant-design/icons";
import { App, Button, Descriptions, Modal, Space, Tooltip } from "antd";
import { formatDateTimeFromIso } from "@CoreBundle/utils";
import { Link } from "react-router-dom";
import React from "react";

export default function SeasonFolderDetailModal({ open, setOpen, selectedId, type }) {
    const [loading, setLoading] = React.useState(true);
    const [listData, setListData] = React.useState({});

    const { modal, message } = App.useApp()
    const api = useBackendApi();

    const afterOpenChange = React.useCallback(opened => {
        if (!opened) {
            setLoading(true);
            setListData({});
            return;
        }
        setLoading(true);
        api.seasonsFolder.getId(selectedId).then(
            res => {
                setListData(res.data);
                setLoading(false);
            },
            err => console.error(err)
        );
    }, [selectedId]);

    const onDeleteOpen = React.useCallback(() => {
        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: 'Are you sure you want to delete this season folder?',
            content: listData.folder,
            onOk() {
                message.open({
                    key: 'season-folder-delete-loader',
                    type: 'loading',
                    content: 'Deleting season folder...',
                    duration: 0,
                });
                return api.seasonsFolder.delete(selectedId).then(
                    res => {
                        message.open({
                            key: 'season-folder-delete-loader',
                            type: 'success',
                            content: 'Season folder deleted successfully',
                            duration: 2.5,
                        });
                        setOpen(false);
                    },
                    err => {
                        message.destroy('season-folder-delete-loader');
                        console.error(err);
                    }
                );
            },
        });
    }, [selectedId]);

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
        <Descriptions column={4} layout={'vertical'} items={[
            {
                key: 1,
                label: 'MyAnimeList ID',
                children: <Link to={`https://myanimelist.net/anime/${listData.id}`} target="_blank">
                    {listData.id}
                </Link>,
                span: 2,
            },
            {
                key: 2,
                label: 'Created',
                children: formatDateTimeFromIso(listData.created),
                span: 2,
            },
            {
                key: 3,
                label: 'Folder',
                children: listData.folder,
                span: 4,
            },
        ]}/>
    </Modal>;

}