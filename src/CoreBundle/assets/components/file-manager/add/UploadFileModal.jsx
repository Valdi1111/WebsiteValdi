import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { formatBytes } from "@CoreBundle/format-utils";
import { CloudUploadOutlined } from "@ant-design/icons";
import { App, Modal, Upload } from "antd";
import React from "react";

export default function UploadFileModal({ visible, setVisible }) {
    const [fileList, setFileList] = React.useState([]);
    const { message } = App.useApp();

    const { api, selectedFolder, reloadFiles } = useFileManager();

    const getExtraData = React.useCallback(file => {
        return {
            original_path: file.name
        };
    });

    const beforeUpload = React.useCallback(file => {
        if (file.type || file.name.includes(".")) {
            return true;
        }
        // Stop folders upload: empty type and no extension
        message.error(`Folders are not supported: ${file.name}`);
        return Upload.LIST_IGNORE; // skip
    });

    const onChange = React.useCallback(info => {
        const newFileList = [...info.fileList];
        if (info.file && info.file.status === 'error') {
            // Read error from response
            const index = newFileList.findIndex(file => file.uid === info.file.uid);
            newFileList[index].response = newFileList[index].response.detail;
        }
        setFileList(newFileList);
        // Update folders and files
        if (newFileList.every(file => file.status === 'done' || file.status === 'error')) {
            reloadFiles();
        }
    });

    return <Modal
        open={visible}
        title={<span>Upload file</span>}
        footer={null}
        onCancel={() => setVisible(false)}
        destroyOnHidden
    >
        <Upload.Dragger
            action={api.fmUploadUrl(selectedFolder.id)}
            beforeUpload={beforeUpload}
            data={getExtraData}
            fileList={fileList}
            onChange={onChange}
            multiple
            pastable
            showUploadList={{
                extra: ({size = 0}) => <span className="ant-upload-hint"> ({formatBytes(size)})</span>,
            }}
        >
            <p className="ant-upload-drag-icon">
                <CloudUploadOutlined />
            </p>
            <p className="ant-upload-text">Click or drag file to this area to upload</p>
            <p className="ant-upload-hint">Support for a single or bulk upload.</p>
        </Upload.Dragger>
    </Modal>;
}