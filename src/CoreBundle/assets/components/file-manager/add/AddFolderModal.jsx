import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Form, Input, Modal } from "antd";
import React from "react";

const nameValidationRules = [
    {
        required: true,
        message: 'Please input the folder name!',
    },
    {
        pattern: /^[^\\/:*?"<>|]+$/,
        message: 'Name contains invalid characters (/ \\ : * ? " < > |)',
    },
    {
        validator: (_, value) => {
            if (!value) return Promise.resolve();
            const trimmed = value.trim();
            if (trimmed !== value) {
                return Promise.reject(new Error('Name cannot start or end with whitespace!'));
            }
            if (trimmed === '.' || trimmed === '..') {
                return Promise.reject(new Error('Invalid folder name!'));
            }
            return Promise.resolve();
        },
    },
];

export default function AddFolderModal({ visible, setVisible }) {
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [form] = Form.useForm();

    const { api, selectedFolder, reloadFolders, reloadFiles } = useFileManager();

    const onAddNew = React.useCallback(data => {
        setConfirmLoading(true);
        api
            .withLoadingMessage({
                key: 'add-new-folder-loader',
                loadingContent: 'Creating folder...',
                successContent: 'Folder created successfully',
            })
            .fmMakeFolder(selectedFolder.id, data.name.trim())
            .then(() => {
                reloadFolders();
                reloadFiles();
                setVisible(false);
            })
            .finally(() => {
                setConfirmLoading(false);
            });
    }, [selectedFolder?.id, reloadFolders, reloadFiles, api, setVisible]);

    return <Modal
        open={visible}
        title={<span>Add new folder</span>}
        onCancel={() => setVisible(false)}
        destroyOnHidden
        okButtonProps={{
            htmlType: 'submit',
        }}
        confirmLoading={confirmLoading}
        modalRender={(dom) =>
            <Form
                form={form}
                layout="vertical"
                name="add_folder_modal"
                clearOnDestroy={true}
                onFinish={(data) => onAddNew(data)}>
                {dom}
            </Form>
        }
    >
        <Form.Item name="name" rules={nameValidationRules}>
            <Input placeholder="New folder" autoFocus />
        </Form.Item>
    </Modal>;
}
