import { useBackendApi } from "@HoyoverseBundle/components/BackendApiContext";
import React, { useState, useEffect, useCallback } from "react";
import { useParams, useNavigate } from "react-router";
import {
    Card,
    Row,
    Col,
    Statistic,
    Select,
    Table,
    Typography,
    Space,
    Button,
    Breadcrumb,
    Spin,
    Empty,
    Tag,
    Progress,
    DatePicker
} from "antd";
import {
    ArrowLeftOutlined,
    ArrowUpOutlined,
    ArrowDownOutlined,
    CalendarOutlined,
    PieChartOutlined,
    BarChartOutlined,
    RocketOutlined,
    CloseCircleOutlined
} from "@ant-design/icons";
import dayjs from "dayjs";

const { Title, Text } = Typography;

export default function HoyoverseGameProfileDiaryPage() {
    const { accountId, gameProfileId } = useParams();
    const navigate = useNavigate();
    const api = useBackendApi();

    const [loadingMeta, setLoadingMeta] = useState(true);
    const [meta, setMeta] = useState(null);

    const [selectedPeriod, setSelectedPeriod] = useState(null);
    const [selectedCurrency, setSelectedCurrency] = useState(null);

    const [loadingSummary, setLoadingSummary] = useState(false);
    const [summary, setSummary] = useState(null);

    // Dedicated pagination and table state
    const [loadingEntries, setLoadingEntries] = useState(false);
    const [items, setItems] = useState([]);
    const [total, setTotal] = useState(0);
    const [filteredAmount, setFilteredAmount] = useState(0);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(15);

    // Hover tracker states for subtle highlights
    const [hoveredAction, setHoveredAction] = useState(null);
    const [hoveredDate, setHoveredDate] = useState(null);

    // Interactive filters for raw log entries
    const [selectedAction, setSelectedAction] = useState(null);
    const [selectedDate, setSelectedDate] = useState(null);

    // Load initial metadata
    useEffect(() => {
        setLoadingMeta(true);
        api
            .withErrorHandling()
            .gameProfiles()
            .getDiaryMeta(accountId, gameProfileId)
            .then((res) => {
                const data = res.data;
                setMeta(data);

                if (data.periods && data.periods.length > 0) {
                    setSelectedPeriod(data.periods[0]);
                }
                if (data.currencies && data.currencies.length > 0) {
                    setSelectedCurrency(data.currencies[0].value);
                }
            })
            .finally(() => {
                setLoadingMeta(false);
            });
    }, [api, accountId, gameProfileId]);

    // Fetch summary data when period or currency changes
    const loadSummary = useCallback(() => {
        if (!selectedPeriod || !selectedCurrency) return;
        setLoadingSummary(true);
        api
            .withErrorHandling()
            .gameProfiles()
            .getDiarySummary(accountId, gameProfileId, {
                period: selectedPeriod,
                currency: selectedCurrency,
            })
            .then((res) => {
                setSummary(res.data);
            })
            .finally(() => {
                setLoadingSummary(false);
            });
    }, [api, accountId, gameProfileId, selectedPeriod, selectedCurrency]);

    // Fetch paginated raw entries
    const fetchEntries = useCallback((targetPage, targetPageSize, targetAction, targetDate) => {
        if (!selectedPeriod || !selectedCurrency) return;

        setLoadingEntries(true);
        api
            .withErrorHandling()
            .gameProfiles()
            .getDiaryEntries(accountId, gameProfileId, {
                period: selectedPeriod,
                currency: selectedCurrency,
                page: targetPage,
                limit: targetPageSize,
                filter: targetAction || undefined,
                date: targetDate || undefined,
            })
            .then((res) => {
                setItems(res.data.items || []);
                setTotal(res.data.total || 0);
                setFilteredAmount(res.data.filtered_amount || 0);
                setPage(res.data.page || targetPage);
                setPageSize(res.data.limit || targetPageSize);
            })
            .finally(() => {
                setLoadingEntries(false);
            });
    }, [api, accountId, gameProfileId, selectedPeriod, selectedCurrency]);

    // Reset filters and reload data when switching period or currency
    useEffect(() => {
        if (selectedPeriod && selectedCurrency) {
            setSelectedAction(null);
            setSelectedDate(null);
            setPage(1);
            loadSummary();
            fetchEntries(1, pageSize, null, null);
        }
    }, [selectedPeriod, selectedCurrency, loadSummary, fetchEntries, pageSize]);

    // Unified Ant Design Table change handler
    const handleTableChange = (pagination) => {
        const nextCurrent = pagination.current;
        const nextPageSize = pagination.pageSize;
        setPage(nextCurrent);
        setPageSize(nextPageSize);
        fetchEntries(nextCurrent, nextPageSize, selectedAction, selectedDate);
    };

    // Handler when clicking a source in the breakdown card
    const handleToggleActionFilter = (actionName) => {
        const nextAction = selectedAction === actionName ? null : actionName;
        setSelectedAction(nextAction);
        setPage(1);
        fetchEntries(1, pageSize, nextAction, selectedDate);
    };

    // Handler when clicking a date in the progression card
    const handleToggleDateFilter = (dateString) => {
        const nextDate = selectedDate === dateString ? null : dateString;
        setSelectedDate(nextDate);
        setPage(1);
        fetchEntries(1, pageSize, selectedAction, nextDate);
    };

    const handleResetFilters = () => {
        setSelectedAction(null);
        setSelectedDate(null);
        setPage(1);
        fetchEntries(1, pageSize, null, null);
    };

    const activeCurrencyObj = meta?.currencies?.find((c) => c.value === selectedCurrency);
    const isPrimaryGacha = activeCurrencyObj?.api_type === 1;

    // Peak day calculation
    const peakDay = React.useMemo(() => {
        if (!summary?.daily_timeline || summary.daily_timeline.length === 0) return null;
        return summary.daily_timeline.reduce((prev, curr) => (curr.amount > prev.amount ? curr : prev), summary.daily_timeline[0]);
    }, [summary]);

    // Available actions for the dropdown filter
    const actionOptions = React.useMemo(() => {
        if (!summary?.breakdown) return [];
        return summary.breakdown.map((b) => ({ label: b.actionName, value: b.actionName }));
    }, [summary]);

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
                    onClick={() => handleToggleActionFilter(text)}
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

    if (loadingMeta) {
        return (
            <div style={{ textAlign: "center", padding: 80 }}>
                <Spin size="large" />
            </div>
        );
    }

    if (!meta || !meta.periods || meta.periods.length === 0) {
        return (
            <div style={{ padding: 24 }}>
                <Button icon={<ArrowLeftOutlined />} onClick={() => navigate("/hoyoverse")}>
                    Back to Accounts
                </Button>
                <Empty
                    style={{ marginTop: 60 }}
                    description="No diary data recorded yet for this profile. Make sure 'Sync Diary Logs' is enabled in the profile settings."
                />
            </div>
        );
    }

    const hasActiveFilters = Boolean(selectedAction || selectedDate);

    return (
        <div style={{ display: "flex", flexDirection: "column", height: "100%", width: "100%", overflow: "hidden" }}>
            {/* Fixed Header Section */}
            <div style={{ padding: "20px 24px 16px 24px", borderBottom: "1px solid rgba(5, 5, 5, 0.06)", flexShrink: 0 }}>
                <Breadcrumb
                    style={{ marginBottom: 12 }}
                    items={[
                        { title: <a onClick={() => navigate("/hoyoverse")}>HoYoverse</a> },
                        { title: `Account #${accountId}` },
                        { title: `${meta.profile.nickname} (${meta.profile.game_name})` },
                        { title: "Diary" },
                    ]}
                />

                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 16 }}>
                    <div>
                        <Title level={3} style={{ margin: 0 }}>Resource Diary</Title>
                        <Text type="secondary">
                            {meta.profile.nickname} - UID: {meta.profile.game_uid}
                        </Text>
                    </div>

                    <Space wrap size="middle">
                        <Select
                            style={{ width: 140 }}
                            value={selectedPeriod}
                            onChange={setSelectedPeriod}
                            prefix={<CalendarOutlined />}
                            options={meta.periods.map((p) => ({ label: p, value: p }))}
                        />
                        <Select
                            style={{ width: 170 }}
                            value={selectedCurrency}
                            onChange={setSelectedCurrency}
                            options={meta.currencies.map((c) => ({ label: c.label, value: c.value }))}
                        />
                        <Button icon={<ArrowLeftOutlined />} onClick={() => navigate("/hoyoverse")}>
                            Back
                        </Button>
                    </Space>
                </div>
            </div>

            {/* Scrollable Body Content */}
            <div style={{ flex: 1, overflowY: "auto", padding: 24, width: "100%" }}>
                {loadingSummary ? (
                    <div style={{ textAlign: "center", padding: 40 }}>
                        <Spin size="large" />
                    </div>
                ) : summary ? (
                    <Space orientation="vertical" size="large" style={{ width: "100%" }}>
                        {/* KPI Statistics */}
                        <Row gutter={[16, 16]}>
                            <Col xs={24} sm={12} md={6}>
                                <Card variant="borderless" hoverable>
                                    <Statistic
                                        title="Total Earned"
                                        value={summary.current_total}
                                        styles={{ content: { color: "#1677ff", fontWeight: "bold" } }}
                                        formatter={(v) => v.toLocaleString()}
                                    />
                                </Card>
                            </Col>
                            <Col xs={24} sm={12} md={6}>
                                <Card variant="borderless" hoverable>
                                    <Statistic
                                        title={`vs Previous Month (${summary.previous_period || "-"})`}
                                        value={Math.abs(summary.percentage_diff ?? 0)}
                                        precision={1}
                                        styles={{
                                            content: {
                                                color: (summary.percentage_diff ?? 0) >= 0 ? "#3f8600" : "#cf1322",
                                                fontWeight: "bold",
                                            },
                                        }}
                                        prefix={(summary.percentage_diff ?? 0) >= 0 ? <ArrowUpOutlined /> : <ArrowDownOutlined />}
                                        suffix="%"
                                    />
                                </Card>
                            </Col>
                            <Col xs={24} sm={12} md={6}>
                                <Card variant="borderless" hoverable>
                                    <Statistic
                                        title="Peak Day"
                                        value={peakDay ? peakDay.amount : 0}
                                        suffix={peakDay ? `(${peakDay.date})` : ""}
                                        styles={{ content: { color: "#722ed1" } }}
                                        formatter={(v) => v.toLocaleString()}
                                    />
                                </Card>
                            </Col>
                            {isPrimaryGacha && (
                                <Col xs={24} sm={12} md={6}>
                                    <Card variant="borderless" hoverable>
                                        <Statistic
                                            title="Pull Equivalent (~160 each)"
                                            value={Math.floor(summary.current_total / 160)}
                                            prefix={<RocketOutlined />}
                                            styles={{ content: { color: "#fa8c16", fontWeight: "bold" } }}
                                        />
                                    </Card>
                                </Col>
                            )}
                        </Row>

                        {/* Breakdown & Daily Timeline Progress with Soft Hover Effects */}
                        <Row gutter={[16, 16]}>
                            <Col xs={24} lg={10}>
                                <Card
                                    title={
                                        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                                            <Space><PieChartOutlined /><span>Earnings by Source</span></Space>
                                            <Text type="secondary" style={{ fontSize: 11, fontWeight: "normal" }}>Click to filter logs</Text>
                                        </div>
                                    }
                                >
                                    {summary.breakdown && summary.breakdown.length > 0 ? (
                                        <div style={{ height: 380, overflowY: "auto", paddingRight: 8 }}>
                                            <Space orientation="vertical" style={{ width: "100%" }} size="middle">
                                                {summary.breakdown.map((item, idx) => {
                                                    const percent = summary.current_total > 0
                                                        ? Math.round((item.total / summary.current_total) * 100)
                                                        : 0;
                                                    const isSelected = selectedAction === item.actionName;
                                                    const isHovered = hoveredAction === item.actionName;

                                                    return (
                                                        <div
                                                            key={idx}
                                                            onClick={() => handleToggleActionFilter(item.actionName)}
                                                            onMouseEnter={() => setHoveredAction(item.actionName)}
                                                            onMouseLeave={() => setHoveredAction(null)}
                                                            style={{
                                                                cursor: "pointer",
                                                                padding: "6px 8px",
                                                                borderRadius: 6,
                                                                transition: "all 0.2s ease-in-out",
                                                                background: isSelected
                                                                    ? "rgba(22, 119, 255, 0.12)"
                                                                    : isHovered
                                                                        ? "rgba(22, 119, 255, 0.05)"
                                                                        : "transparent",
                                                                border: isSelected
                                                                    ? "1px solid #1677ff"
                                                                    : isHovered
                                                                        ? "1px solid rgba(22, 119, 255, 0.25)"
                                                                        : "1px solid transparent",
                                                            }}
                                                        >
                                                            <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 4 }}>
                                                                <Text strong={isSelected || isHovered}>{item.actionName}</Text>
                                                                <Text type="secondary">{item.total.toLocaleString()} ({percent}%)</Text>
                                                            </div>
                                                            <Progress
                                                                percent={percent}
                                                                strokeColor={isSelected ? "#1677ff" : isHovered ? "#4096ff" : "#69b1ff"}
                                                                size="small"
                                                            />
                                                        </div>
                                                    );
                                                })}
                                            </Space>
                                        </div>
                                    ) : (
                                        <div style={{ height: 380, display: "flex", alignItems: "center", justifyContent: "center" }}>
                                            <Empty description="No breakdown data available" />
                                        </div>
                                    )}
                                </Card>
                            </Col>

                            <Col xs={24} lg={14}>
                                <Card
                                    title={
                                        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                                            <Space><BarChartOutlined /><span>Daily Progression</span></Space>
                                            <Text type="secondary" style={{ fontSize: 11, fontWeight: "normal" }}>Click to filter logs</Text>
                                        </div>
                                    }
                                >
                                    {summary.daily_timeline && summary.daily_timeline.length > 0 ? (
                                        <div style={{ height: 380, overflowY: "auto", paddingRight: 8 }}>
                                            {summary.daily_timeline.map((item, idx) => {
                                                const maxAmount = peakDay?.amount || 1;
                                                const percent = Math.min(100, Math.round((item.amount / maxAmount) * 100));
                                                const isSelected = selectedDate === item.date;
                                                const isHovered = hoveredDate === item.date;

                                                return (
                                                    <div
                                                        key={idx}
                                                        onClick={() => handleToggleDateFilter(item.date)}
                                                        onMouseEnter={() => setHoveredDate(item.date)}
                                                        onMouseLeave={() => setHoveredDate(null)}
                                                        style={{
                                                            cursor: "pointer",
                                                            marginBottom: 10,
                                                            padding: "4px 8px",
                                                            borderRadius: 6,
                                                            transition: "all 0.2s ease-in-out",
                                                            background: isSelected
                                                                ? "rgba(82, 196, 26, 0.12)"
                                                                : isHovered
                                                                    ? "rgba(82, 196, 26, 0.05)"
                                                                    : "transparent",
                                                            border: isSelected
                                                                ? "1px solid #52c41a"
                                                                : isHovered
                                                                    ? "1px solid rgba(82, 196, 26, 0.3)"
                                                                    : "1px solid transparent",
                                                        }}
                                                    >
                                                        <div style={{ display: "flex", justifyContent: "space-between", fontSize: 13, marginBottom: 2 }}>
                                                            <Text strong={isSelected || isHovered}>{item.date}</Text>
                                                            <Space size={8}>
                                                                <Text strong style={{ color: "#52c41a" }}>+{item.amount.toLocaleString()}</Text>
                                                                <Text type="secondary" style={{ fontSize: 11 }}>(Cumul: {item.cumulative.toLocaleString()})</Text>
                                                            </Space>
                                                        </div>
                                                        <Progress
                                                            percent={percent}
                                                            strokeColor={isSelected ? "#52c41a" : isHovered ? "#73d13d" : "#95de64"}
                                                            showInfo={false}
                                                            size="small"
                                                        />
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <div style={{ height: 380, display: "flex", alignItems: "center", justifyContent: "center" }}>
                                            <Empty description="No daily trend recorded" />
                                        </div>
                                    )}
                                </Card>
                            </Col>
                        </Row>

                        {/* Raw logs table with Filter Summary and Amount Ratio */}
                        <Card
                            title={
                                <Space size={12} wrap>
                                    <span>Acquisition Ledger (Raw Logs)</span>
                                    {hasActiveFilters ? (
                                        <Tag color="cyan">
                                            Filtered: +{filteredAmount.toLocaleString()} / +{summary.current_total.toLocaleString()} (
                                            {summary.current_total > 0
                                                ? Math.round((filteredAmount / summary.current_total) * 100)
                                                : 0}
                                            %)
                                        </Tag>
                                    ) : (
                                        <Tag color="blue">
                                            Total: +{summary.current_total.toLocaleString()}
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
                                        onChange={(val) => {
                                            setSelectedAction(val || null);
                                            setPage(1);
                                            fetchEntries(1, pageSize, val || null, selectedDate);
                                        }}
                                    />

                                    <DatePicker
                                        allowClear
                                        placeholder="Filter by Date"
                                        value={selectedDate ? dayjs(selectedDate) : null}
                                        onChange={(date, dateString) => {
                                            const cleanDate = dateString || null;
                                            setSelectedDate(cleanDate);
                                            setPage(1);
                                            fetchEntries(1, pageSize, selectedAction, cleanDate);
                                        }}
                                    />

                                    {hasActiveFilters && (
                                        <Button
                                            icon={<CloseCircleOutlined />}
                                            onClick={handleResetFilters}
                                        >
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
                                            <Tag
                                                closable
                                                color="blue"
                                                onClose={() => {
                                                    setSelectedAction(null);
                                                    setPage(1);
                                                    fetchEntries(1, pageSize, null, selectedDate);
                                                }}
                                            >
                                                Source: {selectedAction}
                                            </Tag>
                                        )}
                                        {selectedDate && (
                                            <Tag
                                                closable
                                                color="green"
                                                onClose={() => {
                                                    setSelectedDate(null);
                                                    setPage(1);
                                                    fetchEntries(1, pageSize, selectedAction, null);
                                                }}
                                            >
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
                                loading={loadingEntries}
                                onChange={handleTableChange}
                                pagination={{
                                    current: page,
                                    pageSize: pageSize,
                                    total: total,
                                    showSizeChanger: true,
                                    pageSizeOptions: [10, 15, 25, 50, 100],
                                    showTotal: (totalCount, range) => `${range[0]}-${range[1]} of ${totalCount} entries`,
                                }}
                            />
                        </Card>
                    </Space>
                ) : null}
            </div>
        </div>
    );
}