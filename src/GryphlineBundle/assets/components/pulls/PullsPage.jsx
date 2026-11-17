import { useBackendApi } from "@GryphlineBundle/components/BackendApiContext";
import PullsBannerCard from "@GryphlineBundle/components/pulls/PullsBannerCard";
import PullsMonthlyChart from "@GryphlineBundle/components/pulls/PullsMonthlyChart";
import PullsBannerDetail from "@GryphlineBundle/components/pulls/PullsBannerDetail";
import {
    ArrowLeftOutlined,
    SyncOutlined,
    ReloadOutlined,
    AppstoreOutlined,
} from "@ant-design/icons";
import {
    Typography,
    Space,
    Button,
    Breadcrumb,
    Row,
    Col,
    Spin,
    Select,
    Grid,
} from "antd";
import React, { useState, useEffect, useCallback, useRef } from "react";
import { useParams, useNavigate } from "react-router";

const { Title, Text } = Typography;
const { useBreakpoint } = Grid;

export default function PullsPage() {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;

    const { accountId, gameProfileId } = useParams();
    const navigate = useNavigate();
    const api = useBackendApi();
    const scrollContainerRef = useRef(null);

    const [loadingOverview, setLoadingOverview] = useState(true);
    const [overview, setOverview] = useState(null);

    const [selectedBannerKey, setSelectedBannerKey] = useState(null);

    const [loadingDetail, setLoadingDetail] = useState(false);
    const [detailBanner, setDetailBanner] = useState(null);
    const [items, setItems] = useState([]);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(15);
    const [selectedRarities, setSelectedRarities] = useState([]);
    const [search, setSearch] = useState("");

    const [syncingPulls, setSyncingPulls] = useState(false);

    const loadOverview = useCallback(() => {
        setLoadingOverview(true);
        api
            .withErrorHandling()
            .gameProfiles()
            .getPullsOverview(accountId, gameProfileId)
            .then((res) => {
                const data = res.data;
                setOverview(data);
                if (data.rarity_config && selectedRarities.length === 0) {
                    // Default: show 6★ and 5★, hide 4★ base rarity
                    setSelectedRarities([
                        data.rarity_config.s_rarity,
                        data.rarity_config.a_rarity,
                    ]);
                }
            })
            .finally(() => setLoadingOverview(false));
    }, [api, accountId, gameProfileId]);

    useEffect(() => {
        loadOverview();
    }, [loadOverview]);

    const fetchBannerDetail = useCallback(
        (targetBannerKey, targetPage, targetPageSize, targetRarities, targetSearch) => {
            if (!targetBannerKey) return;

            const currentScroll = scrollContainerRef.current?.scrollTop;
            setLoadingDetail(true);

            api
                .withErrorHandling()
                .gameProfiles()
                .getPulls(accountId, gameProfileId, {
                    banner: targetBannerKey,
                    page: targetPage,
                    limit: targetPageSize,
                    rarities:
                        targetRarities && targetRarities.length > 0
                            ? targetRarities.join(",")
                            : undefined,
                    search: targetSearch || undefined,
                })
                .then((res) => {
                    setDetailBanner(res.data.banner || null);
                    setItems(res.data.items || []);
                    setTotal(res.data.total || 0);
                    setPage(res.data.page || targetPage);
                    setPageSize(res.data.limit || targetPageSize);
                })
                .finally(() => {
                    setLoadingDetail(false);
                    if (currentScroll !== undefined && scrollContainerRef.current) {
                        scrollContainerRef.current.scrollTop = currentScroll;
                    }
                });
        },
        [api, accountId, gameProfileId]
    );

    const handleSelectBanner = (bannerKey) => {
        const defaultRarities = overview?.rarity_config
            ? [overview.rarity_config.s_rarity, overview.rarity_config.a_rarity]
            : [6, 5];
        setSelectedBannerKey(bannerKey);
        setSelectedRarities(defaultRarities);
        setSearch("");
        setPage(1);
        if (scrollContainerRef.current) {
            scrollContainerRef.current.scrollTop = 0;
        }
        fetchBannerDetail(bannerKey, 1, pageSize, defaultRarities, "");
    };

    const handleToggleRarity = (rarityValue) => {
        const exists = selectedRarities.includes(rarityValue);
        let nextRarities = exists
            ? selectedRarities.filter((r) => r !== rarityValue)
            : [...selectedRarities, rarityValue];

        if (nextRarities.length === 0) {
            nextRarities = [rarityValue];
        }

        setSelectedRarities(nextRarities);
        setPage(1);
        fetchBannerDetail(selectedBannerKey, 1, pageSize, nextRarities, search);
    };

    const handleSearchSubmit = () => {
        setPage(1);
        fetchBannerDetail(selectedBannerKey, 1, pageSize, selectedRarities, search);
    };

    const handleResetFilters = () => {
        const defaultRarities = overview?.rarity_config
            ? [overview.rarity_config.s_rarity, overview.rarity_config.a_rarity]
            : [6, 5];
        setSelectedRarities(defaultRarities);
        setSearch("");
        setPage(1);
        fetchBannerDetail(selectedBannerKey, 1, pageSize, defaultRarities, "");
    };

    const handleRefresh = () => {
        loadOverview();
        if (selectedBannerKey) {
            fetchBannerDetail(selectedBannerKey, page, pageSize, selectedRarities, search);
        }
    };

    const handleSyncPulls = () => {
        setSyncingPulls(true);
        api
            .withLoadingMessage({
                key: "sync-pulls-action",
                loadingContent: "Queueing pulls synchronization...",
                successContent: "Pulls synchronization queued in background!",
            })
            .gameProfiles()
            .syncPulls(accountId, gameProfileId)
            .then(() => {
                setTimeout(() => handleRefresh(), 2500);
            })
            .catch(() => {})
            .finally(() => setSyncingPulls(false));
    };

    if (loadingOverview && !overview) {
        return (
            <div style={{ textAlign: "center", padding: 80 }}>
                <Spin size="large" />
            </div>
        );
    }

    const profile = overview?.profile;
    const rarityConfig = overview?.rarity_config;
    const banners = overview?.banners || [];
    const currentBannerSummary =
        detailBanner || banners.find((b) => b.key === selectedBannerKey) || null;

    const breadcrumbItems = [
        { title: <a onClick={() => navigate("/gryphline")}>Gryphline</a> },
        { title: `Account #${accountId}` },
        { title: `${profile?.nickname || "Profile"} (${profile?.game_name || "Arknights: Endfield"})` },
        selectedBannerKey
            ? { title: <a onClick={() => setSelectedBannerKey(null)}>Pulls Tracker</a> }
            : { title: "Pulls Tracker" },
        ...(selectedBannerKey && currentBannerSummary
            ? [{ title: currentBannerSummary.name }]
            : []),
    ];

    return (
        <div
            style={{
                display: "flex",
                flexDirection: "column",
                height: "100%",
                width: "100%",
                overflow: "hidden",
            }}
        >
            {/* Header section aligned with DiaryPage */}
            <div
                style={{
                    padding: isMobile ? "12px 14px" : "20px 24px 16px 24px",
                    borderBottom: "1px solid rgba(5, 5, 5, 0.06)",
                    flexShrink: 0,
                }}
            >
                <Breadcrumb style={{ marginBottom: isMobile ? 8 : 12 }} items={breadcrumbItems} />

                <div
                    style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                        flexWrap: "wrap",
                        gap: isMobile ? 12 : 16,
                    }}
                >
                    <div>
                        <Title level={isMobile ? 4 : 3} style={{ margin: 0 }}>
                            {selectedBannerKey && currentBannerSummary
                                ? currentBannerSummary.name
                                : "Pulls Tracker"}
                        </Title>
                        <Text type="secondary" style={{ fontSize: isMobile ? 12 : 14 }}>
                            {profile?.nickname} - UID: {profile?.game_uid}
                        </Text>
                    </div>

                    <div
                        style={{
                            display: "flex",
                            alignItems: "center",
                            gap: 8,
                            width: isMobile ? "100%" : "auto",
                            flexWrap: "wrap",
                        }}
                    >
                        {selectedBannerKey && (
                            <>
                                <Select
                                    style={{ width: isMobile ? "100%" : 220 }}
                                    value={selectedBannerKey}
                                    onChange={(val) => handleSelectBanner(val)}
                                    options={banners.map((b) => ({
                                        label: `${b.name} (${b.total_pulls})`,
                                        value: b.key,
                                    }))}
                                />
                                <Button
                                    icon={<AppstoreOutlined />}
                                    onClick={() => setSelectedBannerKey(null)}
                                >
                                    All Banners
                                </Button>
                            </>
                        )}

                        {profile?.supports_pulls && (
                            <Button
                                type="primary"
                                icon={<SyncOutlined spin={syncingPulls} />}
                                loading={syncingPulls}
                                onClick={handleSyncPulls}
                                style={{ backgroundColor: "#faad14", borderColor: "#faad14" }}
                            >
                                {isMobile ? "Sync" : "Sync Pulls"}
                            </Button>
                        )}

                        <Button
                            icon={<ReloadOutlined />}
                            loading={loadingOverview || loadingDetail}
                            onClick={handleRefresh}
                        >
                            {!isMobile && "Refresh"}
                        </Button>

                        <Button
                            icon={<ArrowLeftOutlined />}
                            onClick={() =>
                                selectedBannerKey
                                    ? setSelectedBannerKey(null)
                                    : navigate("/gryphline")
                            }
                        >
                            {!isMobile && (selectedBannerKey ? "Back to Overview" : "Back")}
                        </Button>
                    </div>
                </div>
            </div>

            {/* Scrollable Body */}
            <div
                ref={scrollContainerRef}
                style={{
                    flex: 1,
                    overflowY: "auto",
                    padding: isMobile ? "12px 10px" : 24,
                    width: "100%",
                    boxSizing: "border-box",
                }}
            >
                {selectedBannerKey ? (
                    <PullsBannerDetail
                        banner={currentBannerSummary}
                        rarityConfig={rarityConfig}
                        items={items}
                        total={total}
                        page={page}
                        pageSize={pageSize}
                        loading={loadingDetail}
                        selectedRarities={selectedRarities}
                        search={search}
                        onToggleRarity={handleToggleRarity}
                        onSearchChange={setSearch}
                        onSearchSubmit={handleSearchSubmit}
                        onResetFilters={handleResetFilters}
                        onTableChange={(pagination) => {
                            const nextPage =
                                pagination.pageSize !== pageSize ? 1 : pagination.current;
                            setPage(nextPage);
                            setPageSize(pagination.pageSize);
                            fetchBannerDetail(
                                selectedBannerKey,
                                nextPage,
                                pagination.pageSize,
                                selectedRarities,
                                search
                            );
                        }}
                    />
                ) : (
                    <Space
                        orientation="vertical"
                        size={isMobile ? "middle" : "large"}
                        style={{ width: "100%" }}
                    >
                        <Row gutter={[16, 16]} align="stretch">
                            {banners.map((banner) => (
                                <Col key={banner.key} xs={24} md={12}>
                                    <PullsBannerCard
                                        banner={banner}
                                        rarityConfig={rarityConfig}
                                        onViewDetails={handleSelectBanner}
                                    />
                                </Col>
                            ))}
                        </Row>

                        <PullsMonthlyChart
                            monthlyTimeline={overview?.monthly_timeline || []}
                            rarityConfig={rarityConfig}
                        />
                    </Space>
                )}
            </div>
        </div>
    );
}
