import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Button, Flex, Input, Segmented } from "antd";
import {
    AppstoreOutlined,
    ArrowLeftOutlined,
    BarsOutlined,
    EyeInvisibleOutlined,
    EyeOutlined,
    NodeCollapseOutlined,
    NodeExpandOutlined,
    ReloadOutlined
} from "@ant-design/icons";
import React from "react";

/**
 * Global control toolbar for navigation, tree collapse toggle, search, and view switches.
 */
export default function FileManagerToolbar() {
    const [loadingSearch, setLoadingSearch] = React.useState(false);

    const {
        folders, reloadFolders, selectedFolder, setSelectedFolder,
        reloadFiles, setSelectedFile, setClipboard,
        treeDrawerOpen, setTreeDrawerOpen,
        showTree, setShowTree,
        showPreview, setShowPreview,
        isMobile,
    } = useFileManager();

    // Traverse directory tree upwards to locate the immediate parent folder
    const getParentNode = React.useCallback((selected, treeData) => {
        for (let node of treeData) {
            if (node.children) {
                if (node.children.some(child => child.id === selected.id)) {
                    return node;
                }
                const parent = getParentNode(selected, node.children);
                if (parent) {
                    return parent;
                }
            }
        }
        return null;
    }, []);

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
        <Flex style={{ width: "100%", padding: 8 }} justify="space-between" gap="small" wrap="wrap">
            <Flex gap="small">
                {/* Folder tree toggle button */}
                <Button
                    icon={isTreeActive ? <NodeCollapseOutlined /> : <NodeExpandOutlined />}
                    title={isTreeActive ? "Collapse folder tree" : "Expand folder tree"}
                    onClick={handleToggleTree}
                />

                {/* Parent directory navigation */}
                <Button
                    icon={<ArrowLeftOutlined />}
                    disabled={selectedFolder?.id === "/"}
                    onClick={() => {
                        const parent = getParentNode(selectedFolder, folders);
                        if (parent) {
                            setSelectedFolder(parent);
                        }
                    }}
                />

                {/* Reset clipboard and reload all entries */}
                <Button
                    icon={<ReloadOutlined />}
                    onClick={() => {
                        setClipboard(null);
                        reloadFolders().then(t => setSelectedFolder(t[0]));
                        reloadFiles().then(() => setSelectedFile(null));
                    }}
                />
            </Flex>

            {/* Global file and folder search input */}
            <div style={{ flex: 1, minWidth: isMobile ? '100%' : 180, order: isMobile ? 3 : 0 }}>
                <Input.Search placeholder="Search..." allowClear onSearch={onSearch} />
            </div>

            <Flex gap="small">
                {/* Preview pane toggle */}
                <Button
                    icon={showPreview ? <EyeOutlined /> : <EyeInvisibleOutlined />}
                    onClick={togglePreview}
                />

                {/* Layout display switcher */}
                <Segmented options={[
                    { value: 'List', icon: <BarsOutlined/> },
                    { value: 'Kanban', icon: <AppstoreOutlined/> },
                ]}/>
            </Flex>
        </Flex>
    );
}