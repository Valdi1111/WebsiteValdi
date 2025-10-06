import FoldersTreeDropdown from "@CoreBundle/components/file-manager/folders/FoldersTreeDropdown";
import FoldersTreeToolbar from "@CoreBundle/components/file-manager/folders/FoldersTreeToolbar";
import FoldersTreeInfo from "@CoreBundle/components/file-manager/folders/FoldersTreeInfo";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Layout, theme as antdTheme, Tree } from "antd";
import Highlighter from "react-highlight-words";
import React from "react";

export default function FoldersTree() {
    const [expandedIds, setExpandedIds] = React.useState(["/"]);
    const [searchText, setSearchText] = React.useState("");
    const [dropdownOpen, setDropdownOpen] = React.useState(null);
    const [dropdownPosition, setDropdownPosition] = React.useState({ x: 0, y: 0 });

    const { folders, selectedFolder, setSelectedFolder, setSelectedFile } = useFileManager();
    const { token: { controlItemBgActiveHover } } = antdTheme.useToken();

    const filterTreeNode = React.useCallback(({ id }) => {
        return searchText && expandedIds.includes(id);
    }, [searchText, expandedIds]);

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
    }, [searchText, expandedIds]);

    function onSelect(selectedIds, extra) {
        setSelectedFolder(extra.node);
        setSelectedFile(null);
        console.debug('Selected', extra.node.id);
    }

    function onRightClick({ event, node }) {
        setSelectedFolder(node);
        if (node.id === "/") {
            return;
        }
        setDropdownPosition({ x: event.clientX, y: event.clientY });
        setDropdownOpen(true);
    }

    return <Layout style={{ height: '100%' }}>
        <FoldersTreeToolbar
            expandedIds={expandedIds}
            setExpandedIds={setExpandedIds}
            searchText={searchText}
            setSearchText={setSearchText}
        />
        <Layout.Content style={{ maxHeight: '100%', overflowY: "auto" }}>
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
        <FoldersTreeInfo/>
    </Layout>;

}