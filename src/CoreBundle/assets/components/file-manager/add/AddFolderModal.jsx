import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Form, Input, Modal } from "antd";
import React from "react";

/**
 * Add folder modal
 * @param {boolean} visible
 * @param {(visible: boolean) => void} setVisible
 * @returns {React.JSX.Element}
 * @constructor
 */
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
            .fmMakeFolder(selectedFolder.id, data.name)
            .then(res => {
                reloadFolders();
                reloadFiles();
                setVisible(false);
            })
            .finally(() => {
                setConfirmLoading(false);
            });
    }, [selectedFolder?.id]);

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
                name="add_folder_modal"
                clearOnDestroy={true}
                onFinish={(data) => onAddNew(data)}>
                {dom}
            </Form>
        }
    >
        <Form.Item name="name" rules={[
            {
                required: true,
                message: 'Please input the folder name!',
            },
        ]}>
            <Input placeholder={"New folder"}/>
        </Form.Item>
    </Modal>;
}