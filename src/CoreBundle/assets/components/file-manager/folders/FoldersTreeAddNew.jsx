import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import UploadFolderModal from "@CoreBundle/components/file-manager/add/UploadFolderModal";
import UploadFileModal from "@CoreBundle/components/file-manager/add/UploadFileModal";
import AddFolderModal from "@CoreBundle/components/file-manager/add/AddFolderModal";
import AddFileModal from "@CoreBundle/components/file-manager/add/AddFileModal";
import { Dropdown } from "antd";
import {
    FileAddOutlined,
    FolderAddOutlined,
    UploadOutlined
} from "@ant-design/icons";
import React from "react";

export default function FoldersTreeAddNew() {
    const [addFileModal, setAddFileModal] = React.useState(false);
    const [addFolderModal, setAddFolderModal] = React.useState(false);
    const [uploadFileModal, setUploadFileModal] = React.useState(false);
    const [uploadFolderModal, setUploadFolderModal] = React.useState(false);
    const { api } = useFileManager();

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

    return <>
        <AddFileModal
            visible={addFileModal}
            setVisible={setAddFileModal}
            backendFunction={api.fmMakeFile}
        />
        <AddFolderModal
            visible={addFolderModal}
            setVisible={setAddFolderModal}
            backendFunction={api.fmMakeDir}
        />
        <UploadFileModal
            visible={uploadFileModal}
            setVisible={setUploadFileModal}
        />
        <UploadFolderModal
            visible={uploadFolderModal}
            setVisible={setUploadFolderModal}
        />
        <Dropdown.Button
            placement="bottomRight"
            buttonsRender={([l, r]) => [
                React.cloneElement(l, {
                    style: { ...l.props.style, flex: 1 },
                }),
                r,
            ]}
            menu={{ items }}
            arrow
        >
            Add New
        </Dropdown.Button>
    </>;
}