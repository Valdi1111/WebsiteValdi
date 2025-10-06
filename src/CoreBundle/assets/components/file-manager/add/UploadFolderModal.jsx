import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { formatBytes } from "@CoreBundle/format-utils";
import { CloudUploadOutlined } from "@ant-design/icons";
import { App, Modal, Upload } from "antd";
import React from "react";

export default function UploadFolderModal({ visible, setVisible }) {
    const [fileList, setFileList] = React.useState([]);
    const { message } = App.useApp();

    const { api, selectedFolder, reloadFolders, reloadFiles } = useFileManager();

    const getExtraData = React.useCallback(file => {
        return {
            original_path: file.webkitRelativePath
        };
    });

    const beforeUpload = React.useCallback(file => {
        if (file.webkitRelativePath && file.webkitRelativePath.includes("/")) {
            return true;
        }
        // Stop files upload: empty webkitRelativePath
        message.error(`Files are not supported: ${file.name}`);
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
            reloadFolders();
            reloadFiles();
        }
    });

    return <Modal
        open={visible}
        title={<span>Upload folder</span>}
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
            directory
            pastable
            showUploadList={{
                extra: ({size = 0}) => <span className="ant-upload-hint"> ({formatBytes(size)})</span>,
            }}
        >
            <p className="ant-upload-drag-icon">
                <CloudUploadOutlined />
            </p>
            <p className="ant-upload-text">Click or drag folder to this area to upload</p>
            <p className="ant-upload-hint">Support for a single or bulk upload.</p>
        </Upload.Dragger>
    </Modal>;
}