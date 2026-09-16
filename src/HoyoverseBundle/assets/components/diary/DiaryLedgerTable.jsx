import { Card, Space, Tag, Select, DatePicker, Button, Table, Typography, Grid } from "antd";
import { CloseCircleOutlined } from "@ant-design/icons";
import React, { useMemo } from "react";
import dayjs from "dayjs";

const { Text } = Typography;
const { useBreakpoint } = Grid;

export default function DiaryLedgerTable({
                                             items,
                                             total,
                                             filteredAmount,
                                             currentTotal,
                                             page,
                                             pageSize,
                                             loading,
                                             selectedAction,
                                             selectedDate,
                                             breakdown,
                                             onFilterActionChange,
                                             onFilterDateChange,
                                             onResetFilters,
                                             onTableChange,
                                         }) {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;
    const hasActiveFilters = Boolean(selectedAction || selectedDate);

    const actionOptions = useMemo(() => {
        if (!breakdown) return [];
        return breakdown.map((b) => ({ label: b.actionName, value: b.actionName }));
    }, [breakdown]);

    const tableColumns = [
        {
            title: "Date & Time",
            dataIndex: "recorded_at",
            key: "recorded_at",
            width: isMobile ? 120 : 180,
            render: (text) => (
                <span style={{ fontSize: isMobile ? 12 : 14, whiteSpace: "normal" }}>
                    {text}
                </span>
            ),
        },
        {
            title: "Source / Action",
            dataIndex: "action_name",
            key: "action_name",
            ellipsis: true,
            render: (text) => (
                <Tag
                    color={selectedAction === text ? "processing" : "blue"}
                    style={{
                        cursor: "pointer",
                        maxWidth: "100%",
                        overflow: "hidden",
                        textOverflow: "ellipsis",
                        whiteSpace: "nowrap",
                    }}
                    onClick={() => onFilterActionChange(selectedAction === text ? null : text)}
                >
                    {text || "Unknown Action"}
                </Tag>
            ),
        },
        {
            title: "Amount",
            dataIndex: "amount",
            key: "amount",
            align: "right",
            width: isMobile ? 80 : 100,
            render: (amount) => (
                <Text strong style={{ color: amount >= 0 ? "#52c41a" : "#f5222d", whiteSpace: "nowrap" }}>
                    {amount >= 0 ? `+${amount.toLocaleString()}` : amount.toLocaleString()}
                </Text>
            ),
        },
    ];

    return (
        <Card
            styles={{
                header: {
                    height: "auto",
                    padding: isMobile ? "12px 12px" : "16px 20px",
                },
                body: {
                    padding: isMobile ? "12px 8px" : "20px 24px",
                },
            }}
            title={
                <div
                    style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                        flexWrap: "wrap",
                        gap: 12,
                        width: "100%",
                    }}
                >
                    {/* Header Title & Badges */}
                    <Space size={8} wrap style={{ flex: "1 1 auto" }}>
                        <span style={{ fontWeight: 600 }}>Acquisition Ledger</span>
                        {hasActiveFilters ? (
                            <Tag color="cyan">
                                Filtered: +{filteredAmount.toLocaleString()} / +{currentTotal.toLocaleString()} (
                                {currentTotal > 0 ? Math.round((filteredAmount / currentTotal) * 100) : 0}%)
                            </Tag>
                        ) : (
                            <Tag color="blue">
                                Total: +{currentTotal.toLocaleString()}
                            </Tag>
                        )}
                    </Space>

                    {/* Filter controls wrapped inside the title block to prevent horizontal overflow */}
                    <Space wrap size="small" style={{ flex: isMobile ? "1 1 100%" : "0 0 auto" }}>
                        <Select
                            allowClear
                            placeholder="Filter by Source..."
                            style={{ width: isMobile ? "100%" : 180, minWidth: 140 }}
                            value={selectedAction}
                            options={actionOptions}
                            onChange={(val) => onFilterActionChange(val || null)}
                        />

                        <DatePicker
                            allowClear
                            placeholder="Filter by Date"
                            style={{ width: isMobile ? "100%" : 150 }}
                            value={selectedDate ? dayjs(selectedDate) : null}
                            onChange={(date, dateString) => onFilterDateChange(dateString || null)}
                        />

                        {hasActiveFilters && (
                            <Button icon={<CloseCircleOutlined />} onClick={onResetFilters}>
                                Reset
                            </Button>
                        )}
                    </Space>
                </div>
            }
        >
            {hasActiveFilters && (
                <div style={{ marginBottom: 12 }}>
                    <Space size={6} wrap>
                        <Text type="secondary" style={{ fontSize: 12 }}>Active:</Text>
                        {selectedAction && (
                            <Tag closable color="blue" onClose={() => onFilterActionChange(null)}>
                                {selectedAction}
                            </Tag>
                        )}
                        {selectedDate && (
                            <Tag closable color="green" onClose={() => onFilterDateChange(null)}>
                                {selectedDate}
                            </Tag>
                        )}
                    </Space>
                </div>
            )}

            <Table
                rowKey="id"
                size="small"
                columns={tableColumns}
                dataSource={items}
                loading={loading}
                onChange={onTableChange}
                scroll={{ x: isMobile ? 380 : undefined }}
                pagination={{
                    current: page,
                    pageSize: pageSize,
                    total: total,
                    responsive: true,
                    simple: isMobile,
                    showSizeChanger: !isMobile,
                    pageSizeOptions: [10, 15, 25, 50, 100],
                    showTotal: isMobile ? undefined : (totalCount, range) => `${range[0]}-${range[1]} of ${totalCount} entries`,
                    scrollToFirstRowOnChange: false,
                }}
            />
        </Card>
    );
}