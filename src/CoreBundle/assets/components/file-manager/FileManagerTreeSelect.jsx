import FileManagerContext from "@CoreBundle/components/file-manager/FileManagerContext";
import createFileManagerApi from "@CoreBundle/components/file-manager/FileManagerApi";
import AddFolderModal from "@CoreBundle/components/file-manager/add/AddFolderModal";
import { App, Button, Space, Tooltip, TreeSelect } from "antd";
import { PlusOutlined } from "@ant-design/icons";
import React from "react";

export default function ({ apiUrl, value, onChange, showAddButton = true, depth = -1, ignoreLastLevelLeaves = false, ...rest }) {
    const [addFolderModal, setAddFolderModal] = React.useState(false);
    // Selected folder
    const [selectedFolder, setSelectedFolder] = React.useState({
        id: "/",
        key: "/",
        title: "Root",
        children: [],
        isLeaf: false,
    });
    // Folders tree data
    const [folders, setFolders] = React.useState([]);
    const app = App.useApp();

    const api = React.useMemo(() => createFileManagerApi(apiUrl, app), [apiUrl]);

    React.useEffect(() => {
        reloadFolders();
    }, [apiUrl]);

    const reloadFolders = React.useCallback((data = null) => {
        if (data && data.id !== "/") {
            return null;
        }
        return api
            .withErrorHandling()
            .fmFolders("/", depth, ignoreLastLevelLeaves)
            .then(res => {
                setFolders(res.data);
            });
    }, [api, depth, ignoreLastLevelLeaves]);

    const reloadFiles = () => {};

    let content = <TreeSelect
        value={value}
        onChange={(value, label, extra) => {
            if (value === undefined) {
                setSelectedFolder({
                    id: "/",
                    key: "/",
                    title: "Root",
                    children: [],
                    isLeaf: false,
                })
            }
            onChange(value, label, extra);
        }}
        onSelect={(value, node, extra) => {
            setSelectedFolder(node);
        }}
        loadData={reloadFolders}
        treeData={folders}
        fieldNames={{value: 'key'}}
        treeNodeFilterProp={'title'}
        allowClear
        {...rest}
    />;

    if (showAddButton) {
        content = <>
            <AddFolderModal visible={addFolderModal} setVisible={setAddFolderModal}/>
            <Space.Compact block>
                {content}
                <Tooltip title="Add new folder">
                    <Button
                        icon={<PlusOutlined/>}
                        color="primary"
                        variant="outlined"
                        disabled={selectedFolder === null}
                        onClick={() => {
                            setAddFolderModal(true)
                        }}
                    />
                </Tooltip>
            </Space.Compact>
        </>
    }

    return <FileManagerContext value={{
        selectedFolder, setSelectedFolder,
        folders, reloadFolders,
        reloadFiles,
        api,
    }}>
        {content}
    </FileManagerContext>;

}