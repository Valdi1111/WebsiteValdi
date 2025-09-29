import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { App, Form, Input, Modal } from "antd";
import React from "react";

export default function AddFolderModal({ visible, setVisible, backendFunction }) {
    const { selectedId, reloadFolders, reloadFiles } = useFileManager();
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const { message } = App.useApp();
    const [form] = Form.useForm();

    const onAddNew = React.useCallback(data => {
        setConfirmLoading(true);
        message.open({
            key: 'add-new-folder-loader',
            type: 'loading',
            content: 'Creating folder...',
            duration: 0,
        });
        backendFunction(selectedId, data.name).then(
            res => {
                message.open({
                    key: 'add-new-folder-loader',
                    type: 'success',
                    content: 'Folder created successfully',
                    duration: 2.5,
                });
                reloadFolders();
                reloadFiles();
                setVisible(false);
            },
            err => {
                message.destroy('add-new-folder-loader');
                console.error(err);
            }
        ).finally(() => {
            setConfirmLoading(false);
        });
    }, [selectedId]);

    return <Modal
        open={visible}
        title={<span>Add new folder</span>}
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
                message: `Please input the folder name!`,
            },
        ]}>
            <Input placeholder={"New folder"}/>
        </Form.Item>
    </Modal>;
}