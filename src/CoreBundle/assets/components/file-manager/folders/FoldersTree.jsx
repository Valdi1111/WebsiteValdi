import React from "react";
import { Card, Tree, theme as antdTheme, Grid } from "antd";
import Highlighter from "react-highlight-words";
import FoldersTreeDropdown from "@CoreBundle/components/file-manager/folders/FoldersTreeDropdown";
import FoldersTreeToolbar from "@CoreBundle/components/file-manager/folders/FoldersTreeToolbar";
import FoldersTreeInfo from "@CoreBundle/components/file-manager/folders/FoldersTreeInfo";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";

const { useBreakpoint } = Grid;

/**
 * Directory navigation tree component.
 * Rendered inside a styled Card on desktop, or without Card when on mobile / inside a drawer.
 */

// Recursively filter tree nodes, preserving ancestors only if they match or contain matching descendants
function filterTreeNodes(nodes, query) {
    if (!query) return nodes;

    const lowerQuery = query.toLowerCase();

    return nodes.reduce((acc, node) => {
        const titleStr = (node.title || "").toString().toLowerCase();
        const matchesCurrent = titleStr.includes(lowerQuery);

        const filteredChildren = node.children
            ? filterTreeNodes(node.children, query)
            : [];

        // Keep the node if it matches directly or has any matching descendants
        if (matchesCurrent || filteredChildren.length > 0) {
            acc.push({
                ...node,
                children: filteredChildren.length > 0 ? filteredChildren : node.children,
            });
        }

        return acc;
    }, []);
}

// Collect all node IDs to automatically expand matching branches during search
function getAllKeys(nodes) {
    let keys = [];
    nodes.forEach((node) => {
        keys.push(node.id);
        if (node.children) {
            keys = keys.concat(getAllKeys(node.children));
        }
    });
    return keys;
}

export default function FoldersTree({ inDrawer = false }) {
    const screens = useBreakpoint();
    const isMobile = inDrawer || !screens.sm;

    const [expandedIds, setExpandedIds] = React.useState(["/"]);
    const [searchText, setSearchText] = React.useState("");
    const [dropdownOpen, setDropdownOpen] = React.useState(null);
    const [dropdownPosition, setDropdownPosition] = React.useState({ x: 0, y: 0 });

    const { folders, selectedFolder, setSelectedFolder, setSelectedFile } = useFileManager();
    const { token: { controlItemBgActiveHover, colorBorderSecondary } } = antdTheme.useToken();

    // Filtered tree structure based on current search query to exclude non-matching siblings
    const filteredFolders = React.useMemo(() => {
        return filterTreeNodes(folders || [], searchText);
    }, [folders, searchText]);

    // Automatically expand all visible matching nodes when searching
    React.useEffect(() => {
        if (searchText) {
            setExpandedIds(getAllKeys(filteredFolders));
        }
    }, [searchText, filteredFolders]);

    // Filter nodes matching search text that are expanded
    const filterTreeNode = React.useCallback(({ id }) => {
        return searchText && expandedIds.includes(id);
    }, [searchText, expandedIds]);

    // Custom node title renderer with query text highlighting
    const titleRender = React.useCallback((node) => {
        if (!searchText || !expandedIds.includes(node.id)) {
            return node.title;
        }
        return (
            <Highlighter
                highlightStyle={{
                    backgroundColor: controlItemBgActiveHover,
                    color: "inherit",
                    borderRadius: "5px",
                    padding: "2px 0",
                }}
                searchWords={[searchText]}
                autoEscape
                textToHighlight={node.title ? node.title.toString() : ""}
            />
        );
    }, [searchText, expandedIds, controlItemBgActiveHover]);

    function onSelect(selectedIds, extra) {
        setSelectedFolder(extra.node);
        // Clear active file selection when navigating to another folder
        setSelectedFile(null);
        console.debug("Selected", extra.node.id);
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

    // Common tree body and bottom info content
    const treeContent = (
        <>
            <FoldersTreeDropdown
                node={selectedFolder}
                posX={dropdownPosition.x}
                posY={dropdownPosition.y}
                open={dropdownOpen}
                onOpenChange={(newOpen) => setDropdownOpen(newOpen)}
            />

            {/* Scrollable tree container */}
            <div
                style={{
                    flex: 1,
                    minHeight: 0,
                    overflowY: "auto",
                    overflowX: "hidden",
                }}
            >
                <Tree
                    fieldNames={{ key: "id" }}
                    treeData={filteredFolders}
                    filterTreeNode={filterTreeNode}
                    onExpand={(newExpandedIds) => setExpandedIds(newExpandedIds)}
                    expandedKeys={expandedIds}
                    defaultSelectedKeys={["/"]}
                    selectedKeys={selectedFolder ? [selectedFolder.id] : []}
                    onSelect={onSelect}
                    titleRender={titleRender}
                    onRightClick={onRightClick}
                    showLine
                />
            </div>

            {/* Bottom storage statistics and usage indicator anchored at bottom */}
            <div
                style={{
                    flexShrink: 0,
                    marginTop: "auto",
                    paddingTop: 8,
                    paddingLeft: 8,
                    paddingRight: 8,
                    borderTop: `1px solid ${colorBorderSecondary || "#f0f0f0"}`,
                }}
            >
                <FoldersTreeInfo />
            </div>
        </>
    );

    // On mobile / inside a drawer, render directly without the Card wrapper
    if (isMobile) {
        return (
            <div
                style={{
                    height: "100%",
                    display: "flex",
                    flexDirection: "column",
                    overflow: "hidden",
                }}
            >
                <div style={{ flexShrink: 0 }}>
                    <FoldersTreeToolbar
                        expandedIds={expandedIds}
                        setExpandedIds={setExpandedIds}
                        searchText={searchText}
                        setSearchText={setSearchText}
                        style={{ paddingTop: 8, paddingBottom: 8 }}
                    />
                </div>
                {treeContent}
            </div>
        );
    }

    // On desktop, wrap in Card and constrain vertical height with an explicit bottom padding on the outer container
    return (
        <Card
            styles={{
                header: {
                    padding: "14px 16px",
                    height: "auto",
                    flexShrink: 0,
                },
                body: {
                    padding: 8,
                    flex: 1,
                    minHeight: 0,
                    display: "flex",
                    flexDirection: "column",
                    overflow: "hidden",
                },
            }}
            style={{
                height: "100%",
                display: "flex",
                flexDirection: "column",
                minHeight: 0,
                boxSizing: "border-box",
            }}
            title={
                <FoldersTreeToolbar
                    expandedIds={expandedIds}
                    setExpandedIds={setExpandedIds}
                    searchText={searchText}
                    setSearchText={setSearchText}
                />
            }
        >
            {treeContent}
        </Card>
    );
}
