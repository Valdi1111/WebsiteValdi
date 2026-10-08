import React from "react";
import { Flex, Space, Tag, Typography, Button, Tooltip, Popover, Checkbox } from "antd";
import { ReloadOutlined, SettingOutlined } from "@ant-design/icons";

const { Text } = Typography;

/**
 * Header toolbar component for StandardTable.
 * Manages titles, filtered record counters, table reload trigger, and column visibility picker.
 *
 * @param {Object} props
 * @param {React.ReactNode} [props.title] Main table header title
 * @param {React.ReactNode} [props.subtitle] Secondary table header description
 * @param {boolean} props.isMobile Whether the viewport is mobile sized
 * @param {boolean} props.hasActiveFilters Whether one or more filters are currently applied
 * @param {number} props.total Filtered row count
 * @param {number} props.unfilteredTotal Global unfiltered row count
 * @param {number} props.filterPercent Filtered percentage of total
 * @param {React.ReactNode} [props.extraToolbarActions] Additional user-provided action buttons
 * @param {Function} props.onReload Callback to trigger table data reload
 * @param {Array<Object>} props.columns Original column configuration definitions
 * @param {Object} props.columnVisibility Visibility state map keyed by column dataIndex
 * @param {Function} props.onToggleColumnVisibility Callback invoked when toggling column checkbox
 */
export default function TableToolbar({
                                         title,
                                         subtitle,
                                         isMobile,
                                         hasActiveFilters,
                                         total,
                                         unfilteredTotal,
                                         filterPercent,
                                         extraToolbarActions,
                                         onReload,
                                         columns,
                                         columnVisibility,
                                         onToggleColumnVisibility,
                                     }) {
    // Checkbox list content rendered inside the column visibility popover
    const columnSettingsContent = (
        <Flex vertical gap="small" style={{ maxHeight: 320, overflowY: "auto", minWidth: 170 }}>
            {columns.map((col) => (
                <Checkbox
                    key={col.dataIndex}
                    checked={columnVisibility[col.dataIndex] === true}
                    onChange={(e) => onToggleColumnVisibility(col.dataIndex, e.target.checked)}
                >
                    {col.title}
                </Checkbox>
            ))}
        </Flex>
    );

    return (
        <Flex
            justify="space-between"
            align={isMobile ? "stretch" : "center"}
            vertical={isMobile}
            gap={12}
            style={{ width: "100%", whiteSpace: "normal" }}
        >
            {/* Title, Subtitle, and Counts Badges */}
            <div style={{ flex: 1, minWidth: 0 }}>
                <Space size={8} wrap align="center">
                    {title && (
                        typeof title === "string" ? (
                            <Text strong style={{ fontSize: isMobile ? 15 : 16 }}>
                                {title}
                            </Text>
                        ) : (
                            title
                        )
                    )}

                    {/* Count badges */}
                    {hasActiveFilters ? (
                        <Tag color="cyan">
                            Filtered: {total.toLocaleString()} / {unfilteredTotal.toLocaleString()} ({filterPercent}%)
                        </Tag>
                    ) : (
                        <Tag color="blue">
                            Total: {unfilteredTotal.toLocaleString()}
                        </Tag>
                    )}
                </Space>

                {subtitle && (
                    <div style={{ marginTop: 2 }}>
                        <Text
                            type="secondary"
                            style={{
                                fontSize: 12,
                                whiteSpace: "normal",
                                wordBreak: "break-word",
                                display: "block",
                                lineHeight: 1.4,
                            }}
                        >
                            {subtitle}
                        </Text>
                    </div>
                )}
            </div>

            {/* Toolbar action buttons */}
            <Space wrap size="small" style={{ alignSelf: isMobile ? "flex-end" : "center", flexShrink: 0 }}>
                {extraToolbarActions}
                <Tooltip title="Reload">
                    <Button icon={<ReloadOutlined />} onClick={onReload} />
                </Tooltip>
                <Popover
                    content={columnSettingsContent}
                    title="Display Columns"
                    trigger="click"
                    placement="bottomRight"
                >
                    <Tooltip title="Column Settings">
                        <Button icon={<SettingOutlined />} />
                    </Tooltip>
                </Popover>
            </Space>
        </Flex>
    );
}
