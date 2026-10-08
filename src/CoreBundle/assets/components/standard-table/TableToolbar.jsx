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
 * @param {Array<Object>} [props.columns] Original column configuration definitions
 * @param {Object} [props.columnVisibility] Visibility state map keyed by column dataIndex
 * @param {Function} [props.onToggleColumnVisibility] Callback invoked when toggling column checkbox
 * @param {boolean} [props.compactHeaderOnMobile] Keep title alongside action buttons and truncate subtitle to single line on mobile
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
                                         columns = [],
                                         columnVisibility = {},
                                         onToggleColumnVisibility,
                                         compactHeaderOnMobile = false,
                                     }) {
    // Checkbox list content rendered inside the column visibility popover.
    // Filters out action/utility columns without titles and ensures a guaranteed unique key.
    const columnSettingsContent = (
        <Flex vertical gap="small" style={{ maxHeight: 320, overflowY: "auto", minWidth: 170 }}>
            {columns
                .filter((col) => col.dataIndex && col.title)
                .map((col, idx) => {
                    const identifier = col.dataIndex || col.key || `col_${idx}`;
                    return (
                        <Checkbox
                            key={identifier}
                            checked={columnVisibility[col.dataIndex] === true}
                            onChange={(e) => onToggleColumnVisibility(col.dataIndex, e.target.checked)}
                        >
                            {col.title}
                        </Checkbox>
                    );
                })}
        </Flex>
    );

    const isCompact = isMobile && compactHeaderOnMobile;

    // Helper renderer for active count badges
    const renderBadge = () => {
        {/* Count badges with explicit key to prevent React key warning */}
        if (hasActiveFilters) {
            return (
                <Tag
                    key="badge-filtered"
                    color="cyan"
                    style={{
                        fontSize: isCompact ? 11 : 12,
                        padding: "0 6px",
                        lineHeight: isCompact ? "18px" : "20px",
                        marginInlineEnd: 0,
                    }}
                >
                    {isCompact ? `${total}/${unfilteredTotal}` : `Filtered: ${total.toLocaleString()} / ${unfilteredTotal.toLocaleString()} (${filterPercent}%)`}
                </Tag>
            );
        }
        return (
            <Tag
                key="badge-total"
                color="blue"
                style={{
                    fontSize: isCompact ? 11 : 12,
                    padding: "0 6px",
                    lineHeight: isCompact ? "18px" : "20px",
                    marginInlineEnd: 0,
                }}
            >
                {isCompact ? unfilteredTotal.toLocaleString() : `Total: ${unfilteredTotal.toLocaleString()}`}
            </Tag>
        );
    };

    return (
        <Flex
            justify="space-between"
            align="center"
            vertical={isMobile && !compactHeaderOnMobile}
            gap={isCompact ? 8 : 12}
            style={{ width: "100%", whiteSpace: "nowrap" }}
        >
            {/* Title, Subtitle, and Counts Badges */}
            <div style={{ flex: 1, minWidth: 0, overflow: "hidden" }}>
                {isCompact ? (
                    /* Compact mobile layout: Title & Badge inline on top, single-line ellipsis subtitle below */
                    <Flex vertical gap={2} style={{ width: "100%", minWidth: 0 }}>
                        <Flex align="center" gap={8} style={{ minWidth: 0, overflow: "hidden" }}>
                            {title && (
                                typeof title === "string" ? (
                                    <Text
                                        strong
                                        ellipsis
                                        style={{
                                            fontSize: 15,
                                            lineHeight: 1.2,
                                        }}
                                    >
                                        {title}
                                    </Text>
                                ) : (
                                    title
                                )
                            )}
                            {renderBadge()}
                        </Flex>

                        {subtitle && (
                            <Text
                                type="secondary"
                                ellipsis
                                style={{
                                    fontSize: 11,
                                    lineHeight: 1.2,
                                    display: "block",
                                    width: "100%",
                                    overflow: "hidden",
                                    textOverflow: "ellipsis",
                                    whiteSpace: "nowrap",
                                }}
                            >
                                {subtitle}
                            </Text>
                        )}
                    </Flex>
                ) : (
                    /* Standard desktop / full mobile layout */
                    <>
                        <Space size={8} wrap align="center">
                            {title && (
                                typeof title === "string" ? (
                                    <Text strong style={{ fontSize: isMobile ? 15 : 16 }}>
                                        {title}
                                    </Text>
                                ) : (
                                    <span key="title-custom">{title}</span>
                                )
                            )}
                            {renderBadge()}
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
                    </>
                )}
            </div>

            {/* Toolbar action buttons */}
            <Space
                size={isCompact ? 6 : "small"}
                style={{
                    alignSelf: isMobile && !compactHeaderOnMobile ? "flex-end" : "center",
                    flexShrink: 0,
                }}
            >
                {extraToolbarActions}
                <Tooltip title="Reload">
                    <Button
                        icon={<ReloadOutlined />}
                        onClick={onReload}
                    />
                </Tooltip>
                <Popover
                    content={columnSettingsContent}
                    title="Display Columns"
                    trigger="click"
                    placement="bottomRight"
                >
                    <Tooltip title="Column Settings">
                        <Button
                            icon={<SettingOutlined />}
                        />
                    </Tooltip>
                </Popover>
            </Space>
        </Flex>
    );
}
