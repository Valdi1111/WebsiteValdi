import FileManagerContext from "@CoreBundle/components/file-manager/FileManagerContext";
import createFileManagerApi from "@CoreBundle/components/file-manager/FileManagerApi";
import AddFolderModal from "@CoreBundle/components/file-manager/add/AddFolderModal";
import { App, Button, Space, Tooltip, TreeSelect } from "antd";
import { PlusOutlined } from "@ant-design/icons";
import React from "react";

/**
 * Dropdown TreeSelect component for selecting target folders in forms and pickers.
 * Optionally includes an inline quick-add folder button.
 */
export default function FileManagerTreeSelect({
    apiUrl,
    value,
    onChange,
    showAddButton = true,
    depth = -1,
    ignoreLastLevelLeaves = false,
    ...rest
}) {
    const [addFolderModal, setAddFolderModal] = React.useState(false);
    const [selectedFolder, setSelectedFolder] = React.useState({
        id: "/",
        key: "/",
        title: "Root",
        children: [],
        isLeaf: false,
    });
    const [folders, setFolders] = React.useState([]);
    const app = App.useApp();

    const api = React.useMemo(() => createFileManagerApi(apiUrl, app), [apiUrl]);

    // Fetch folder nodes for tree selection
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

    React.useEffect(() => {
        reloadFolders();
    }, [apiUrl, reloadFolders]);

    // Stub placeholder for interface compliance with FileManagerContext
    const reloadFiles = () => {};

    let content = (
        <TreeSelect
            value={value}
            onChange={(val, label, extra) => {
                if (val === undefined) {
                    setSelectedFolder({
                        id: "/",
                        key: "/",
                        title: "Root",
                        children: [],
                        isLeaf: false,
                    });
                }
                onChange(val, label, extra);
            }}
            onSelect={(val, node) => {
                setSelectedFolder(node);
            }}
            loadData={reloadFolders}
            treeData={folders}
            fieldNames={{ value: 'key' }}
            treeNodeFilterProp={'title'}
            allowClear
            {...rest}
        />
    );

    // Render an inline plus button beside the TreeSelect if quick folder creation is enabled
    if (showAddButton) {
        content = (
            <>
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
                                setAddFolderModal(true);
                            }}
                        />
                    </Tooltip>
                </Space.Compact>
            </>
        );
    }

    return (
        <FileManagerContext value={{
            selectedFolder, setSelectedFolder,
            folders, reloadFolders,
            reloadFiles,
            api,
        }}>
            {content}
        </FileManagerContext>
    );
}