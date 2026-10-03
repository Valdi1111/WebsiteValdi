import FoldersTreeAddNew from "@CoreBundle/components/file-manager/folders/FoldersTreeAddNew";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Button, Flex, Input, Popover } from "antd";
import { FilterOutlined } from "@ant-design/icons";
import React from "react";

/**
 * Top control bar for the folder tree. Provides directory creation triggers
 * and recursive folder name filtering.
 */
export default function FoldersTreeToolbar({ expandedIds, setExpandedIds, searchText, setSearchText }) {
    const { folders } = useFileManager();

    // Recursively traverse folder hierarchy to locate matches and expand parent nodes
    const onSearch = React.useCallback((value, e, info) => {
        setSearchText(value);
        if (!value) {
            setExpandedIds(["/"]);
            return;
        }

        const nodesToExpand = new Set();
        function traverse(node, parentIds = []) {
            const currentIds = [...parentIds, node.id];
            // Include node path if title matches filter query
            if (node.title.toLowerCase().includes(value.toLowerCase())) {
                currentIds.forEach(id => nodesToExpand.add(id));
            }
            // Traverse child directories if available
            if (node.children && node.children.length > 0) {
                node.children.forEach(child => traverse(child, currentIds));
            }
        }

        folders.forEach(node => traverse(node));
        setExpandedIds(Array.from(nodesToExpand));
    }, [folders, setSearchText, setExpandedIds]);

    return (
        <Flex gap="small" style={{ paddingTop: 7, paddingBottom: 8 }}>
            <FoldersTreeAddNew/>
            <Popover
                placement="left"
                arrow
                content={
                    <Input.Search placeholder="Search folders" onSearch={onSearch} allowClear/>
                }
            >
                <Button style={{ paddingLeft: 8, paddingRight: 8 }} icon={<FilterOutlined/>}/>
            </Popover>
        </Flex>
    );
}