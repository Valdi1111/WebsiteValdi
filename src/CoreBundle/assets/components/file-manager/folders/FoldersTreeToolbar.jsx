import FoldersTreeAddNew from "@CoreBundle/components/file-manager/folders/FoldersTreeAddNew";
import { Flex, Input } from "antd";
import { SearchOutlined } from "@ant-design/icons";
import React from "react";

/**
 * Top control bar for the folder tree. Provides a prominent search input
 * and a compact action trigger for folder creation.
 */
export default function FoldersTreeToolbar({ searchText, setSearchText, setExpandedIds }) {
    // Handle real-time query updates and clear behavior
    const handleChange = React.useCallback((e) => {
        const val = e.target.value;
        setSearchText(val);
        // Reset to root expansion when search is cleared
        if (!val && setExpandedIds) {
            setExpandedIds(["/"]);
        }
    }, [setSearchText, setExpandedIds]);

    return (
        <Flex gap="small" align="center" style={{ paddingTop: 8, paddingBottom: 8 }}>
            {/* Expanded search input that takes the available width */}
            <Input
                placeholder="Search folders..."
                prefix={<SearchOutlined />}
                value={searchText}
                onChange={handleChange}
                allowClear
                size="middle"
                style={{ flex: 1 }}
            />

            {/* Compact creation trigger with Add text and plus icon */}
            <div style={{ flexShrink: 0 }}>
                <FoldersTreeAddNew />
            </div>
        </Flex>
    );
}
