import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import {Button, Flex, Input, Segmented, Tooltip} from "antd";
import {
    AppstoreOutlined,
    BarsOutlined,
    EyeInvisibleOutlined,
    EyeOutlined,
    NodeCollapseOutlined,
    NodeExpandOutlined,
} from "@ant-design/icons";
import React from "react";

/**
 * Global control toolbar for navigation, tree collapse toggle, search, and view switches.
 */
export default function FileManagerToolbar() {
    const [loadingSearch, setLoadingSearch] = React.useState(false);

    const {
        treeDrawerOpen, setTreeDrawerOpen,
        showTree, setShowTree,
        showPreview, setShowPreview,
        isMobile,
    } = useFileManager();

    function onSearch(value, e, info) {
        // TODO: Implement backend file search integration
        setLoadingSearch(true);
        setTimeout(() => setLoadingSearch(false), 3000);
    }

    function togglePreview() {
        setShowPreview(!showPreview);
    }

    // Unified tree collapse/expand toggle: toggles drawer on mobile, collapses Splitter panel on desktop
    const handleToggleTree = () => {
        if (isMobile) {
            setTreeDrawerOpen(prev => !prev);
        } else {
            setShowTree(prev => !prev);
        }
    };

    const isTreeActive = isMobile ? treeDrawerOpen : showTree;

    return (
        <Flex
            style={{ width: "100%", padding: 8 }}
            justify="space-between"
            align="center"
            gap="small"
            wrap="nowrap"
        >
            {/* Folder tree toggle button */}
            <Tooltip title={isTreeActive ? "Collapse folder tree" : "Expand folder tree"}>
                <Button
                    icon={isTreeActive ? <NodeCollapseOutlined /> : <NodeExpandOutlined />}
                    onClick={handleToggleTree}
                />
            </Tooltip>

            {/* Global file and folder search input: positioned inline between tree toggle and view switchers */}
            <div style={{ flex: 1, minWidth: 0 }}>
                <Input.Search placeholder="Search..." allowClear onSearch={onSearch} />
            </div>

            <Flex gap="small" align="center" style={{ flexShrink: 0 }}>
                {/* Preview pane toggle: visible only on desktop */}
                {!isMobile && (
                    <Tooltip title={showPreview ? "Show file preview" : "Hide file preview"}>
                        <Button
                            icon={showPreview ? <EyeOutlined /> : <EyeInvisibleOutlined />}
                            onClick={togglePreview}
                        />
                    </Tooltip>
                )}

                {/* Layout display switcher */}
                <Segmented
                    options={[
                        { value: "List", icon: <BarsOutlined /> },
                        { value: "Kanban", icon: <AppstoreOutlined /> },
                    ]}
                />
            </Flex>
        </Flex>
    );
}
