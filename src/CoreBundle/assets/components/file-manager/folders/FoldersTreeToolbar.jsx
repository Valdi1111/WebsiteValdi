import FoldersTreeAddNew from "@CoreBundle/components/file-manager/folders/FoldersTreeAddNew";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Flex, Input, Button, Tooltip, Space } from "antd";
import { SearchOutlined, ReloadOutlined } from "@ant-design/icons";
import React from "react";

/**
 * Top control bar for the folder tree. Provides a search input,
 * directory reload trigger, and quick-add button.
 */
export default function FoldersTreeToolbar({ searchText, setSearchText, setExpandedIds, style = {} }) {
    const { reloadFolders, selectedFolder, setSelectedFolder, setClipboard, reloadFiles, setSelectedFile } = useFileManager();

    // Handle real-time query updates and clear behavior
    const handleChange = React.useCallback((e) => {
        const val = e.target.value;
        setSearchText(val);
        // Reset to root expansion when search is cleared
        if (!val && setExpandedIds) {
            setExpandedIds(["/"]);
        }
    }, [setSearchText, setExpandedIds]);

    const findFolderInTree = (nodes, id) => {
        for (let node of nodes) {
            if (node.id === id) return node;
            if (node.children?.length) {
                const found = findFolderInTree(node.children, id);
                if (found) return found;
            }
        }
        return null;
    };

    const handleRefresh = () => {
        setClipboard(null);
        reloadFolders().then((freshTree) => {
            const currentId = selectedFolder?.id;
            const targetFolder = currentId ? findFolderInTree(freshTree, currentId) : null;
            setSelectedFolder(targetFolder || freshTree[0]);
        });
        reloadFiles().then(() => setSelectedFile(null));
    };

    return (
        <Flex gap="small" align="center" style={{ width: "100%", ...style }}>
            {/* Expanded search input that takes the available width */}
            <Input
                placeholder="Search folders..."
                prefix={<SearchOutlined />}
                value={searchText}
                onChange={handleChange}
                allowClear
                size="middle"
                style={{ flex: 1, minWidth: 0 }}
            />

            {/* Actions: Reload tree and Add new entry */}
            <Space size={4} style={{ flexShrink: 0 }}>
                <Tooltip title="Reload folders">
                    <Button icon={<ReloadOutlined />} onClick={handleRefresh} />
                </Tooltip>
                <FoldersTreeAddNew />
            </Space>
        </Flex>
    );
}
