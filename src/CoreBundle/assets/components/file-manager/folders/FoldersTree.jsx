import FoldersTreeDropdown from "@CoreBundle/components/file-manager/folders/FoldersTreeDropdown";
import FoldersTreeToolbar from "@CoreBundle/components/file-manager/folders/FoldersTreeToolbar";
import FoldersTreeInfo from "@CoreBundle/components/file-manager/folders/FoldersTreeInfo";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Layout, theme as antdTheme, Tree } from "antd";
import Highlighter from "react-highlight-words";
import React from "react";

/**
 * Directory navigation tree component.
 * Handles folder selection, search highlighting, expansion, and context menu actions.
 */
export default function FoldersTree() {
    const [expandedIds, setExpandedIds] = React.useState(["/"]);
    const [searchText, setSearchText] = React.useState("");
    const [dropdownOpen, setDropdownOpen] = React.useState(null);
    const [dropdownPosition, setDropdownPosition] = React.useState({ x: 0, y: 0 });

    const { folders, selectedFolder, setSelectedFolder, setSelectedFile } = useFileManager();
    const { token: { controlItemBgActiveHover } } = antdTheme.useToken();

    // Filter nodes matching search text that are expanded
    const filterTreeNode = React.useCallback(({ id }) => {
        return searchText && expandedIds.includes(id);
    }, [searchText, expandedIds]);

    // Custom node title renderer with query text highlighting
    const titleRender = React.useCallback((node) => {
        if (!searchText || !expandedIds.includes(node.id)) {
            return node.title;
        }
        return <Highlighter
            highlightStyle={{
                backgroundColor: controlItemBgActiveHover,
                borderRadius: '5px',
                padding: '2px 0',
            }}
            searchWords={[searchText]}
            autoEscape
            textToHighlight={node.title ? node.title.toString() : ''}
        />;
    }, [searchText, expandedIds, controlItemBgActiveHover]);

    function onSelect(selectedIds, extra) {
        setSelectedFolder(extra.node);
        // Clear active file selection when navigating to another folder
        setSelectedFile(null);
        console.debug('Selected', extra.node.id);
    }

    function onRightClick({ event, node }) {
        setSelectedFolder(node);
        // Prevent opening the context menu on the root folder
        if (node.id === "/") {
            return;
        }
        setDropdownPosition({ x: event.clientX, y: event.clientY });
        setDropdownOpen(true);
    }

    return (
        <Layout style={{ height: '100%', display: 'flex', flexDirection: 'column', overflow: 'hidden', background: 'transparent' }}>
            {/* Top action toolbar (Add, filter, and search inputs) */}
            <div style={{ flexShrink: 0 }}>
                <FoldersTreeToolbar
                    expandedIds={expandedIds}
                    setExpandedIds={setExpandedIds}
                    searchText={searchText}
                    setSearchText={setSearchText}
                />
            </div>

            {/* Scrollable tree area; flex: 1 and minHeight: 0 constrain overflow exclusively to this container */}
            <Layout.Content style={{ flex: 1, minHeight: 0, overflowY: "auto", overflowX: "hidden" }}>
                <FoldersTreeDropdown
                    node={selectedFolder}
                    posX={dropdownPosition.x}
                    posY={dropdownPosition.y}
                    open={dropdownOpen}
                    onOpenChange={(newOpen) => setDropdownOpen(newOpen)}
                />
                <Tree
                    fieldNames={{ key: 'id' }}
                    treeData={folders}
                    filterTreeNode={filterTreeNode}
                    onExpand={newExpandedIds => setExpandedIds(newExpandedIds)}
                    expandedKeys={expandedIds}
                    defaultSelectedKeys={["/"]}
                    selectedKeys={selectedFolder ? [selectedFolder.id] : []}
                    onSelect={onSelect}
                    titleRender={titleRender}
                    onRightClick={onRightClick}
                    showLine
                />
            </Layout.Content>

            {/* Bottom storage statistics and usage indicator */}
            <div style={{ flexShrink: 0 }}>
                <FoldersTreeInfo />
            </div>
        </Layout>
    );
}