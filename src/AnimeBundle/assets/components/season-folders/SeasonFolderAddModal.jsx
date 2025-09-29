import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { FolderOpenOutlined, GlobalOutlined } from "@ant-design/icons";
import { App, Form, Input, Modal, TreeSelect } from "antd";
import React from "react";

const MAL_ANIME_URL_PATTERN = /^https:\/\/myanimelist\.net\/anime\/(\d+)(?:\/.*)?$/;

export default function SeasonFolderAddModal({ open, setOpen }) {
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [form] = Form.useForm();
    const { message } = App.useApp();

    const [treeData, setTreeData] = React.useState([]);
    const api = useBackendApi();

    const afterOpenChange = React.useCallback(opened => {
        if (!opened) {
            setTreeData([]);
            return;
        }
        onLoadTreeData();
    }, []);

    const onSubmit = React.useCallback(data => {
        setConfirmLoading(true);
        message.open({
            key: 'season-folder-add-loader',
            type: 'loading',
            content: 'Adding season folder...',
            duration: 0,
        });
        api.seasonsFolder.add(data).then(
            res => {
                message.open({
                    key: 'season-folder-add-loader',
                    type: 'success',
                    content: 'Season folder added successfully',
                    duration: 2.5,
                });
                setOpen(false);
            },
            err => {
                message.destroy('season-folder-add-loader');
                console.error(err);
            }
        ).finally(() => {
            setConfirmLoading(false);
        });
    }, []);

    function onLoadTreeData(node) {
        const data = {};
        if (node) {
            data.path = node.id;
        }
        return api.seasonsFolder.available(data).then(
            res => {
                setTreeData(treeData.concat(res.data));
                return res.data;
            },
            err => {
                console.error(err);
            }
        );
    }

    return <Modal
        open={open}
        afterOpenChange={afterOpenChange}
        title={<span>Add season folder</span>}
        onCancel={() => setOpen(false)}
        destroyOnHidden
        okButtonProps={{
            autoFocus: true,
            htmlType: 'submit',
        }}
        confirmLoading={confirmLoading}
        modalRender={(dom) =>
            <Form
                form={form}
                layout="vertical"
                name="form_in_modal"
                clearOnDestroy={true}
                onFinish={data => {
                    // pre-elaborazione prima del submit
                    const match = data.url.match(MAL_ANIME_URL_PATTERN);
                    if (match) {
                        data.id = Number.parseInt(match[1]);
                        delete data.url;
                        onSubmit(data);
                    }
                }}>
                {dom}
            </Form>
        }
    >
        <Form.Item label="Url MyAnimeList" name="url" rules={[
            { required: true, message: 'Please input season url.' },
            { pattern: MAL_ANIME_URL_PATTERN, message: 'Invalid season url.' }
        ]}>
            <Input prefix={<GlobalOutlined/>} placeholder="https://myanimelist.net/anime/xxxxx"/>
        </Form.Item>
        <Form.Item label="Folder" name="folder" rules={[{ required: true, message: 'Please input season folder.' }]}>
            <TreeSelect
                prefix={<FolderOpenOutlined/>}
                placeholder="Folder"
                treeDataSimpleMode
                showSearch
                treeLine
                style={{ width: '100%' }}
                styles={{
                    popup: { root: { maxHeight: 400, overflow: 'auto' } },
                }}
                loadData={onLoadTreeData}
                treeData={treeData}
            />
        </Form.Item>
    </Modal>;

}