import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { App, Form, Input, Modal } from "antd";
import React from "react";

/**
 * Add file modal
 * @param {boolean} visible
 * @param {(visible: boolean) => void} setVisible
 * @returns {React.JSX.Element}
 * @constructor
 */
export default function AddFileModal({ visible, setVisible }) {
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [form] = Form.useForm();

    const { api, selectedFolder, reloadFiles } = useFileManager();

    const onAddNew = React.useCallback(data => {
        setConfirmLoading(true);
        api
            .withLoadingMessage({
                key: 'add-new-file-loader',
                loadingContent: 'Creating file...',
                successContent: 'File created successfully',
            })
            .fmMakeFile(selectedFolder.id, data.name)
            .then(res => {
                reloadFiles();
                setVisible(false);
            })
            .finally(() => {
                setConfirmLoading(false);
            });
    }, [selectedFolder?.id]);

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
                name="add_file_modal"
                clearOnDestroy={true}
                onFinish={(data) => onAddNew(data)}>
                {dom}
            </Form>
        }
    >
        <Form.Item name="name" rules={[
            {
                required: true,
                message: 'Please input the file name!',
            },
        ]}>
            <Input placeholder={"New file.txt"}/>
        </Form.Item>
    </Modal>;
}