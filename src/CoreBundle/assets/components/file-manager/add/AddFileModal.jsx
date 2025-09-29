import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { App, Form, Input, Modal } from "antd";
import React from "react";

export default function AddFileModal({ visible, setVisible, type, placeholder, backendFunction }) {
    const { selectedId, reloadFiles } = useFileManager();
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const { message } = App.useApp();
    const [form] = Form.useForm();

    const onAddNew = React.useCallback(data => {
        setConfirmLoading(true);
        message.open({
            key: 'add-new-file-loader',
            type: 'loading',
            content: 'Creating file...',
            duration: 0,
        });
        backendFunction(selectedId, data.name).then(
            res => {
                message.open({
                    key: 'add-new-file-loader',
                    type: 'success',
                    content: 'File created successfully',
                    duration: 2.5,
                });
                reloadFiles();
                setVisible(false);
            },
            err => {
                message.destroy('add-new-file-loader');
                console.error(err);
            }
        ).finally(() => {
            setConfirmLoading(false);
        });
    }, [selectedId]);

    return <Modal
        open={visible}
        title={<span>Add new file</span>}
        onCancel={() => setVisible(false)}
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
                onFinish={(data) => onAddNew(data)}>
                {dom}
            </Form>
        }
    >
        <Form.Item name="name" rules={[
            {
                required: true,
                message: `Please input the file name!`,
            },
        ]}>
            <Input placeholder={"New file.txt"}/>
        </Form.Item>
    </Modal>;
}