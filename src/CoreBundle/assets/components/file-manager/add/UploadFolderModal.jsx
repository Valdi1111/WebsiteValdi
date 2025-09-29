import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { UploadOutlined } from "@ant-design/icons";
import { Button, Modal, Upload } from "antd";
import React from "react";

export default function UploadFolderModal({ visible, setVisible }) {
    const { api, selectedId, reloadFolders, reloadFiles } = useFileManager();
    const [fileList, setFileList] = React.useState([]);

    const getExtraData = React.useCallback(file => {
        return {
            original_path: file.webkitRelativePath
        };
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
        title={<span>Upload new folder</span>}
        footer={null}
        onCancel={() => setVisible(false)}
        destroyOnHidden
    >
        <Upload
            action={api.fmUploadUrl(selectedId)}
            data={getExtraData}
            fileList={fileList}
            onChange={onChange}
            directory
        >
            <Button icon={<UploadOutlined/>}>Upload Directory</Button>
        </Upload>
    </Modal>;
}