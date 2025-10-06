import UploadFolderModal from "@CoreBundle/components/file-manager/add/UploadFolderModal";
import UploadFileModal from "@CoreBundle/components/file-manager/add/UploadFileModal";
import AddFolderModal from "@CoreBundle/components/file-manager/add/AddFolderModal";
import AddFileModal from "@CoreBundle/components/file-manager/add/AddFileModal";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { FileAddOutlined, FolderAddOutlined, UploadOutlined } from "@ant-design/icons";
import { Button, Dropdown } from "antd";
import React from "react";

export default function FoldersTreeAddNew() {
    const [addFileModal, setAddFileModal] = React.useState(false);
    const [addFolderModal, setAddFolderModal] = React.useState(false);
    const [uploadFileModal, setUploadFileModal] = React.useState(false);
    const [uploadFolderModal, setUploadFolderModal] = React.useState(false);

    const { selectedFolder } = useFileManager();

    const items = [
        {
            key: 'addFile',
            label: 'Add new file',
            icon: <FileAddOutlined/>,
            onClick: () => setAddFileModal(true),
        },
        {
            key: 'addFolder',
            label: 'Add new folder',
            icon: <FolderAddOutlined/>,
            onClick: () => setAddFolderModal(true),
        },
        {
            type: 'divider',
        },
        {
            key: 'uploadFile',
            label: 'Upload file',
            icon: <UploadOutlined/>,
            onClick: () => setUploadFileModal(true),
        },
        {
            key: 'uploadFolder',
            label: 'Upload folder',
            icon: <UploadOutlined/>,
            onClick: () => setUploadFolderModal(true),
        },
    ];

    let components = <></>;

    if (selectedFolder) {
        components = <>
            <AddFileModal visible={addFileModal} setVisible={setAddFileModal}/>
            <AddFolderModal visible={addFolderModal} setVisible={setAddFolderModal}/>
            <UploadFileModal visible={uploadFileModal} setVisible={setUploadFileModal}/>
            <UploadFolderModal visible={uploadFolderModal} setVisible={setUploadFolderModal}/>
        </>;
    }

    return <>
        {components}
        <Dropdown
            disabled={selectedFolder == null}
            placement="bottom"
            menu={{ items }}
            arrow={{ pointAtCenter: true }}
        >
            <Button style={{ flex: 1 }} disabled={selectedFolder == null}>Add New</Button>
        </Dropdown>
    </>;
}