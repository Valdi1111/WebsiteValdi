import React from "react";
import FilesTableRowDropdown from "@CoreBundle/components/file-manager/files/FilesTableRowDropdown";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { formatBytes, formatDateFromTimestamp } from "@CoreBundle/format-utils";
import { ArrowUpOutlined, FolderFilled, MoreOutlined } from "@ant-design/icons";
import { Button, Flex, Tooltip, theme as antdTheme } from "antd";
import LocalTable from "@CoreBundle/components/standard-table/LocalTable";
import "./FilesTable.css";

/**
 * File manager table component powered by LocalTable for client-side sorting,
 * multi-condition filtering, and responsive mobile drawers.
 */
export default function FilesTable() {
    const {
        folders,
        files,
        filesLoading,
        selectedFolder,
        setSelectedFolder,
        selectedFile,
        setSelectedFile,
        setShowPreview,
        isMobile,
        api,
        reloadFiles,
    } = useFileManager();

    // Retrieve active theme tokens (Light or Dark) for row highlighting
    const { token: { controlItemBgActive, controlItemBgActiveHover } } = antdTheme.useToken();

    // Traverse directory tree upwards to locate the immediate parent folder
    const getParentNode = React.useCallback((selected, treeData) => {
        if (!selected || !treeData) return null;
        for (let node of treeData) {
            if (node.children) {
                if (node.children.some((child) => child.id === selected.id)) {
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

    const columns = [
        {
            title: "Name",
            dataIndex: "title",
            filterType: "text",
            defaultSortOrder: "ascend",
            sortDirections: ["ascend", "descend", "ascend"],
            sorter: (a, b) => {
                // Ensure folders always precede regular files
                if (a.type === "folder" && b.type !== "folder") return -1;
                if (a.type !== "folder" && b.type === "folder") return 1;
                return (a.title || "").localeCompare(b.title || "", "it");
            },
            render: (text, row) => {
                const icon = row.type === "folder" ? (
                    <FolderFilled style={{ fontSize: 24, color: "#faad14", flexShrink: 0 }} />
                ) : (
                    <img
                        src={api.fmIconUrl("small", row.type, row.extension)}
                        alt=""
                        style={{ width: 24, height: 24, objectFit: "contain", flexShrink: 0 }}
                    />
                );

                return (
                    <Flex align="center" gap={10} style={{ minWidth: 0, paddingLeft: 8 }}>
                        {icon}
                        <div style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                            {text}
                        </div>
                    </Flex>
                );
            },
            className: "fm-table-title-td",
        },
        {
            title: "Size",
            dataIndex: "size",
            filterType: "number",
            width: 130,
            render: (size, row) => (row.type === "folder" ? "-" : formatBytes(size)),
            sortDirections: ["ascend", "descend"],
            sorter: (a, b) => {
                // Keep folders on top even when sorting by file size
                if (a.type === "folder" && b.type !== "folder") return -1;
                if (a.type !== "folder" && b.type === "folder") return 1;
                return (a.size || 0) - (b.size || 0);
            },
            className: "fm-table-size-td",
        },
        {
            title: "Date",
            dataIndex: "date",
            filterType: "date",
            width: 170,
            sortDirections: ["ascend", "descend"],
            sorter: (a, b) => {
                // Keep folders on top even when sorting by modification timestamp
                if (a.type === "folder" && b.type !== "folder") return -1;
                if (a.type !== "folder" && b.type === "folder") return 1;
                return (a.date || 0) - (b.date || 0);
            },
            render: formatDateFromTimestamp,
            className: "fm-table-date-td",
        },
        {
            title: "",
            key: "actions",
            width: 44,
            align: "center",
            fixed: "right", // Pin the action column to the right edge during horizontal scrolling
            render: (_, row) => (
                // Prevent bubbling so clicking the action trigger doesn't toggle row selection
                <div onClick={(e) => e.stopPropagation()}>
                    <FilesTableRowDropdown row={row} trigger={["click"]}>
                        <Button
                            type="text"
                            size="small"
                            icon={<MoreOutlined style={{ fontSize: 18 }} />}
                        />
                    </FilesTableRowDropdown>
                </div>
            ),
        },
    ];

    function onRowClick(record) {
        if (isMobile) {
            if (record.type === "folder") {
                // Directly navigate into folder on mobile to avoid double-tap issues
                setSelectedFolder(record);
                return;
            }
            // For files: select and immediately open the preview drawer
            setSelectedFile(record);
            setShowPreview?.(true);
            return;
        }

        // Desktop default: select row on single click
        setSelectedFile(record);
    }

    function onRowDoubleClick(record) {
        if (record.type === "folder") {
            setSelectedFolder(record);
            return;
        }
        // Direct download / open link in a new browser tab on desktop double-click
        const link = document.createElement("a");
        link.href = api.fmDirectUrl(record.id);
        link.target = "_blank";
        link.click();
        link.remove();
    }

    const folderTitle = selectedFolder?.title || "Files";
    const folderPath = selectedFolder?.id && selectedFolder.id !== "/" ? selectedFolder.id : "Root directory";
    const isRoot = !selectedFolder || selectedFolder.id === "/";

    const extraActions = (
        <Tooltip title="Go to parent folder">
            <Button
                icon={<ArrowUpOutlined />}
                disabled={isRoot}
                onClick={() => {
                    const parent = getParentNode(selectedFolder, folders);
                    if (parent) {
                        setSelectedFolder(parent);
                    }
                }}
            />
        </Tooltip>
    );

    return (
        <div
            style={{
                height: "100%",
                width: "100%",
                minWidth: 0,
                display: "flex",
                flexDirection: "column",
                overflow: "hidden",
                // Dynamic CSS variables bound to the current Ant Design theme
                "--fm-selected-bg": controlItemBgActive,
                "--fm-selected-hover-bg": controlItemBgActiveHover,
            }}
        >
            <LocalTable
                rowKey="id"
                title={folderTitle}
                subtitle={folderPath}
                columns={columns}
                dataSource={files}
                loading={filesLoading}
                pagination={false}
                fillHeight={true}
                compactHeaderOnMobile={true}
                styles={{
                    cardBodyStyle: { padding: 8 },
                    tableFilterRibbonStyle: { marginBottom: 8 },
                }}
                extraToolbarActions={extraActions}
                sticky={true}
                scroll={{ x: 600 }}
                onReload={reloadFiles}
                rowClassName={(record) => (record.id === selectedFile?.id ? "fm-table-row-selected" : "")}
                onRow={(record, rowIndex) => ({
                    onClick: (e) => onRowClick(record, rowIndex, e),
                    onDoubleClick: (e) => onRowDoubleClick(record, rowIndex, e),
                })}
                components={{
                    body: {
                        row: (props) => {
                            const row = files.find((f) => f.id === props["data-row-key"]);
                            return (
                                <FilesTableRowDropdown row={row}>
                                    <tr {...props} className={`${props.className ?? ""} fm-table-tr`} />
                                </FilesTableRowDropdown>
                            );
                        },
                    },
                }}
            />
        </div>
    );
}
