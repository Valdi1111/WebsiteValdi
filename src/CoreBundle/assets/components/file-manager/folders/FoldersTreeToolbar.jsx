import FoldersTreeAddNew from "@CoreBundle/components/file-manager/folders/FoldersTreeAddNew";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Button, Flex, Input, Popover } from "antd";
import { FilterOutlined } from "@ant-design/icons";
import React from "react";

export default function FoldersTreeToolbar({ expandedIds, setExpandedIds, searchText, setSearchText }) {
    const { folders } = useFileManager();

    const onSearch = React.useCallback((value, e, info) => {
        setSearchText(value);
        if (!value) {
            setExpandedIds(["/"]);
            return;
        }
        const nodesToExpand = new Set();
        function traverse(node, parentIds = []) {
            const currentIds = [...parentIds, node.id];
            // Add note to list if the title contains the searched string
            if (node.title.toLowerCase().includes(value.toLowerCase())) {
                currentIds.forEach(id => nodesToExpand.add(id));
            }
            // Search in children if present
            if (node.children && node.children.length > 0) {
                node.children.forEach(child => traverse(child, currentIds));
            }
        }
        // Start traversing from tree root
        folders.forEach(node => traverse(node));
        // Convert set to array
        setExpandedIds(Array.from(nodesToExpand));
    }, [folders]);

    return <Flex gap="small" style={{ paddingBottom: "10px", paddingLeft: "10px", paddingRight: "10px" }}>
        <FoldersTreeAddNew/>
        <Popover placement={"left"} arrow content={
            <Input.Search placeholder="Search folders" onSearch={onSearch} allowClear/>
        }>
            <Button style={{ paddingLeft: "8px", paddingRight: "8px" }} icon={<FilterOutlined/>}/>
        </Popover>
    </Flex>;

}