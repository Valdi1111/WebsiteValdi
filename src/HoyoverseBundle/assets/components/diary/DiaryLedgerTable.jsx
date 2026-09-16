import { Card, Space, Tag, Select, DatePicker, Button, Table, Typography } from "antd";
import { CloseCircleOutlined } from "@ant-design/icons";
import React, { useMemo } from "react";
import dayjs from "dayjs";

const { Text } = Typography;

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
            width: 200,
        },
        {
            title: "Source / Action",
            dataIndex: "action_name",
            key: "action_name",
            render: (text) => (
                <Tag
                    color={selectedAction === text ? "processing" : "blue"}
                    style={{ cursor: "pointer" }}
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
            render: (amount) => (
                <Text strong style={{ color: amount >= 0 ? "#52c41a" : "#f5222d" }}>
                    {amount >= 0 ? `+${amount.toLocaleString()}` : amount.toLocaleString()}
                </Text>
            ),
        },
    ];

    return (
        <Card
            title={
                <Space size={12} wrap>
                    <span>Acquisition Ledger (Raw Logs)</span>
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
            }
            extra={
                <Space wrap size="middle">
                    <Select
                        allowClear
                        placeholder="Filter by Source..."
                        style={{ width: 200 }}
                        value={selectedAction}
                        options={actionOptions}
                        onChange={(val) => onFilterActionChange(val || null)}
                    />

                    <DatePicker
                        allowClear
                        placeholder="Filter by Date"
                        value={selectedDate ? dayjs(selectedDate) : null}
                        onChange={(date, dateString) => onFilterDateChange(dateString || null)}
                    />

                    {hasActiveFilters && (
                        <Button icon={<CloseCircleOutlined />} onClick={onResetFilters}>
                            Reset Filters
                        </Button>
                    )}
                </Space>
            }
        >
            {hasActiveFilters && (
                <div style={{ marginBottom: 16 }}>
                    <Space size={8} wrap>
                        <Text type="secondary" style={{ fontSize: 12 }}>Active Filters:</Text>
                        {selectedAction && (
                            <Tag closable color="blue" onClose={() => onFilterActionChange(null)}>
                                Source: {selectedAction}
                            </Tag>
                        )}
                        {selectedDate && (
                            <Tag closable color="green" onClose={() => onFilterDateChange(null)}>
                                Date: {selectedDate}
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
                pagination={{
                    current: page,
                    pageSize: pageSize,
                    total: total,
                    showSizeChanger: true,
                    pageSizeOptions: [10, 15, 25, 50, 100],
                    showTotal: (totalCount, range) => `${range[0]}-${range[1]} of ${totalCount} entries`,
                    scrollToFirstRowOnChange: false,
                }}
            />
        </Card>
    );
}