import DiaryKpiCards from "@HoyoverseBundle/components/diary/DiaryKpiCards";
import DiaryVisualBreakdown from "@HoyoverseBundle/components/diary/DiaryVisualBreakdown";
import DiaryLedgerTable from "@HoyoverseBundle/components/diary/DiaryLedgerTable";

import { useBackendApi } from "@HoyoverseBundle/components/BackendApiContext";
import { Typography, Space, Button, Breadcrumb, Select, Spin, Empty } from "antd";
import { ArrowLeftOutlined, CalendarOutlined } from "@ant-design/icons";
import React, { useState, useEffect, useCallback, useRef } from "react";
import { useParams, useNavigate } from "react-router";

const { Title, Text } = Typography;

export default function DiaryPage() {
    const { accountId, gameProfileId } = useParams();
    const navigate = useNavigate();
    const api = useBackendApi();
    const scrollContainerRef = useRef(null);

    const [loadingMeta, setLoadingMeta] = useState(true);
    const [meta, setMeta] = useState(null);

    const [selectedPeriod, setSelectedPeriod] = useState(null);
    const [selectedCurrency, setSelectedCurrency] = useState(null);

    const [loadingSummary, setLoadingSummary] = useState(false);
    const [summary, setSummary] = useState(null);

    const [loadingEntries, setLoadingEntries] = useState(false);
    const [items, setItems] = useState([]);
    const [total, setTotal] = useState(0);
    const [filteredAmount, setFilteredAmount] = useState(0);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(15);

    const [selectedAction, setSelectedAction] = useState(null);
    const [selectedDate, setSelectedDate] = useState(null);

    // Initial metadata fetch
    useEffect(() => {
        setLoadingMeta(true);
        api
            .withErrorHandling()
            .gameProfiles()
            .getDiaryMeta(accountId, gameProfileId)
            .then((res) => {
                const data = res.data;
                setMeta(data);
                if (data.periods?.length > 0) setSelectedPeriod(data.periods[0]);
                if (data.currencies?.length > 0) setSelectedCurrency(data.currencies[0].value);
            })
            .finally(() => setLoadingMeta(false));
    }, [api, accountId, gameProfileId]);

    // Fetch summary
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
            .then((res) => setSummary(res.data))
            .finally(() => setLoadingSummary(false));
    }, [api, accountId, gameProfileId, selectedPeriod, selectedCurrency]);

    // Fetch ledger entries preservando la posizione di scroll
    const fetchEntries = useCallback((targetPage, targetPageSize, targetAction, targetDate) => {
        if (!selectedPeriod || !selectedCurrency) return;

        const currentScroll = scrollContainerRef.current?.scrollTop;
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
                if (currentScroll !== undefined && scrollContainerRef.current) {
                    scrollContainerRef.current.scrollTop = currentScroll;
                }
            });
    }, [api, accountId, gameProfileId, selectedPeriod, selectedCurrency]);

    // Reload data ONLY when switching period or currency
    useEffect(() => {
        if (selectedPeriod && selectedCurrency) {
            setSelectedAction(null);
            setSelectedDate(null);
            setPage(1);
            loadSummary();
            fetchEntries(1, pageSize, null, null);
        }
    }, [selectedPeriod, selectedCurrency]);

    const handleFilterActionChange = (action) => {
        setSelectedAction(action);
        setPage(1);
        fetchEntries(1, pageSize, action, selectedDate);
    };

    const handleFilterDateChange = (date) => {
        setSelectedDate(date);
        setPage(1);
        fetchEntries(1, pageSize, selectedAction, date);
    };

    const handleResetFilters = () => {
        setSelectedAction(null);
        setSelectedDate(null);
        setPage(1);
        fetchEntries(1, pageSize, null, null);
    };

    const isPrimaryGacha = meta?.currencies?.find((c) => c.value === selectedCurrency)?.api_type === 1;

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

    return (
        <div style={{ display: "flex", flexDirection: "column", height: "100%", width: "100%", overflow: "hidden" }}>
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

            <div ref={scrollContainerRef} style={{ flex: 1, overflowY: "auto", padding: 24, width: "100%" }}>
                {loadingSummary ? (
                    <div style={{ textAlign: "center", padding: 40 }}>
                        <Spin size="large" />
                    </div>
                ) : summary ? (
                    <Space orientation="vertical" size="large" style={{ width: "100%" }}>
                        <DiaryKpiCards summary={summary} isPrimaryGacha={isPrimaryGacha} />

                        <DiaryVisualBreakdown
                            summary={summary}
                            selectedAction={selectedAction}
                            selectedDate={selectedDate}
                            onToggleActionFilter={(act) => handleFilterActionChange(selectedAction === act ? null : act)}
                            onToggleDateFilter={(d) => handleFilterDateChange(selectedDate === d ? null : d)}
                        />

                        <DiaryLedgerTable
                            items={items}
                            total={total}
                            filteredAmount={filteredAmount}
                            currentTotal={summary.current_total}
                            page={page}
                            pageSize={pageSize}
                            loading={loadingEntries}
                            selectedAction={selectedAction}
                            selectedDate={selectedDate}
                            breakdown={summary.breakdown}
                            onFilterActionChange={handleFilterActionChange}
                            onFilterDateChange={handleFilterDateChange}
                            onResetFilters={handleResetFilters}
                            onTableChange={(pagination) => {
                                const nextPage = pagination.pageSize !== pageSize ? 1 : pagination.current;
                                setPage(nextPage);
                                setPageSize(pagination.pageSize);
                                fetchEntries(nextPage, pagination.pageSize, selectedAction, selectedDate);
                            }}
                        />
                    </Space>
                ) : null}
            </div>
        </div>
    );
}