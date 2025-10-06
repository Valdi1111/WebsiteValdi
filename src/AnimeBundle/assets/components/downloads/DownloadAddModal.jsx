import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { Checkbox, Form, Input, Modal } from "antd";
import { GlobalOutlined } from "@ant-design/icons";
import React from "react";

export default function DownloadAddModal({ open, setOpen }) {
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [form] = Form.useForm();
    const api = useBackendApi();

    const onSubmit = React.useCallback(data => {
        setConfirmLoading(true);
        api
            .withLoadingMessage({
                key: 'download-add-loader',
                loadingContent: 'Adding download...',
                successContent: 'Download added successfully',
            })
            .downloads()
            .add(data)
            .then(res => {
                setOpen(false);
            })
            .finally(() => setConfirmLoading(false));
    }, []);

    return <Modal
        open={open}
        title={<span>Add download</span>}
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
                name="add_download_modal"
                clearOnDestroy={true}
                onFinish={data => onSubmit(data)}>
                {dom}
            </Form>
        }
    >
        <Form.Item label="Url" name="url" rules={[{ required: true, message: 'Please input download url.' }]}>
            <Input prefix={<GlobalOutlined/>} placeholder="Url"/>
        </Form.Item>
        <Form.Item name="all" valuePropName="checked" initialValue={false}>
            <Checkbox>All episodes</Checkbox>
        </Form.Item>
        <Form.Item name="filter" valuePropName="checked" initialValue={true}>
            <Checkbox>Skip if not on MyAnimeList</Checkbox>
        </Form.Item>
        <Form.Item name="save" valuePropName="checked" initialValue={true}>
            <Checkbox>Download</Checkbox>
        </Form.Item>
    </Modal>;

}