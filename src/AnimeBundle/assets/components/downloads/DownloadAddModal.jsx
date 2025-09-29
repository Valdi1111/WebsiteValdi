import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { App, Checkbox, Form, Input, Modal } from "antd";
import { GlobalOutlined } from "@ant-design/icons";
import React from "react";

export default function DownloadAddModal({ open, setOpen }) {
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [form] = Form.useForm();
    const { message } = App.useApp();
    const api = useBackendApi();

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
                name="form_in_modal"
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