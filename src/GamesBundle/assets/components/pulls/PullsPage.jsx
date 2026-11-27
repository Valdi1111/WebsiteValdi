import BackupPullImportModal from "@GamesBundle/components/pulls/BackupPullImportModal";
import PullOperationProgressBanner from "@GamesBundle/components/pulls/PullOperationProgressBanner";
import usePullOperationProgress from "@GamesBundle/components/pulls/usePullOperationProgress";
import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { useGameProvider } from "@GamesBundle/components/GameProviderContext";
import PullsBannerCard from "@GamesBundle/components/pulls/PullsBannerCard";
import PullsMonthlyChart from "@GamesBundle/components/pulls/PullsMonthlyChart";
import PullsBannerDetail from "@GamesBundle/components/pulls/PullsBannerDetail";
import PullsShareModal from "@GamesBundle/components/pulls/PullsShareModal";
import {
    ArrowLeftOutlined,
    SyncOutlined,
    ReloadOutlined,
    AppstoreOutlined,
    ImportOutlined,
    DownOutlined,
    FileTextOutlined,
    ShareAltOutlined,
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
    Dropdown,
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
    const provider = useGameProvider();
    const scrollContainerRef = useRef(null);

    const [loadingOverview, setLoadingOverview] = useState(true);
    const [overview, setOverview] = useState(null);

    // null = Overview landing page, string = selected banner key for detail view
    const [selectedBannerKey, setSelectedBannerKey] = useState(null);

    // Detail view state
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [detailBanner, setDetailBanner] = useState(null);
    const [items, setItems] = useState([]);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(15);
    const [selectedRarities, setSelectedRarities] = useState([]);
    const [search, setSearch] = useState("");

    // Key of the open provider-specific importer modal (see provider.pullsExtraImporters)
    const [openExtraImporterKey, setOpenExtraImporterKey] = useState(null);
    const [selectedBackupImporter, setSelectedBackupImporter] = useState(null);
    const [syncingPulls, setSyncingPulls] = useState(false);
    const [shareOpen, setShareOpen] = useState(false);

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

    const fetchOperationStatus = useCallback(
        (operationId) =>
            api.gameProfiles().getPullsOperationStatus(accountId, gameProfileId, operationId),
        [api, accountId, gameProfileId]
    );

    const handleRefreshRef = useRef(null);
    const onOperationCompleted = useCallback(() => {
        handleRefreshRef.current?.();
    }, []);

    const {
        activeOperation,
        applyOperationUpdate,
        mergeInitialOperation,
        dismissOperation,
    } = usePullOperationProgress({
        mercureTopic: overview?.mercure_topic,
        fetchOperationStatus,
        onOperationCompleted,
    });

    const loadOverview = useCallback(() => {
        setLoadingOverview(true);
        api
            .withErrorHandling()
            .gameProfiles()
            .getPullsOverview(accountId, gameProfileId)
            .then((res) => {
                const data = res.data;
                setOverview(data);
                if (data.active_operation) {
                    mergeInitialOperation(data.active_operation);
                }
                if (data.rarity_config) {
                    setSelectedRarities((prev) =>
                        prev.length === 0
                            ? [data.rarity_config.s.rarity, data.rarity_config.a.rarity]
                            : prev
                    );
                }
            })
            .finally(() => setLoadingOverview(false));
    }, [api, accountId, gameProfileId, mergeInitialOperation]);

    useEffect(() => {
        loadOverview();
    }, [loadOverview]);

    const handleRefresh = useCallback(() => {
        loadOverview();
        if (selectedBannerKey) {
            fetchBannerDetail(selectedBannerKey, page, pageSize, selectedRarities, search);
        }
    }, [
        loadOverview,
        selectedBannerKey,
        fetchBannerDetail,
        page,
        pageSize,
        selectedRarities,
        search,
    ]);

    handleRefreshRef.current = handleRefresh;

    // Manual pull mutations are not tracked operations (onOperationCompleted never fires for them):
    // refresh through the ref so the latest page and filters are reloaded
    const handlePullsChanged = useCallback(() => {
        handleRefreshRef.current?.();
    }, []);

    const handleSelectBanner = (bannerKey) => {
        const defaultRarities = overview?.rarity_config
            ? [overview.rarity_config.s.rarity, overview.rarity_config.a.rarity]
            : [];
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

        // Keep at least one rarity selected
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
            ? [overview.rarity_config.s.rarity, overview.rarity_config.a.rarity]
            : [];
        setSelectedRarities(defaultRarities);
        setSearch("");
        setPage(1);
        fetchBannerDetail(selectedBannerKey, 1, pageSize, defaultRarities, "");
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
            .then((res) => {
                if (res?.data?.operation) {
                    applyOperationUpdate(res.data.operation);
                }
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
    const supportsManualPulls = Boolean(profile?.supports_manual_pulls);
    // activeOperation stays set after completion until dismissed: only queued/running block mutations
    const operationActive =
        syncingPulls ||
        activeOperation?.status === "queued" ||
        activeOperation?.status === "running";
    const rarityConfig = overview?.rarity_config;
    const banners = overview?.banners || [];
    const availableBackupImporters = overview?.available_backup_importers || [];
    const currentBannerSummary =
        detailBanner || banners.find((b) => b.key === selectedBannerKey) || null;

    // Build unified Import dropdown menu (provider-specific importers + divider + available backup importers)
    const extraImporters = (provider.pullsExtraImporters || [])
        .filter((importer) => !importer.isAvailable || importer.isAvailable(profile));
    const openExtraImporter = extraImporters.find((importer) => importer.key === openExtraImporterKey) || null;
    const importMenuItems = extraImporters.map((importer) => ({
        key: importer.key,
        icon: importer.icon,
        label: importer.label,
        onClick: () => setOpenExtraImporterKey(importer.key),
    }));
    if (extraImporters.length > 0 && availableBackupImporters.length > 0) {
        importMenuItems.push({ type: "divider" });
    }
    availableBackupImporters.forEach((importer) => {
        importMenuItems.push({
            key: `backup:${importer.key}`,
            icon: <FileTextOutlined />,
            label: importer.label,
            onClick: () => setSelectedBackupImporter(importer),
        });
    });

    const breadcrumbItems = [
        { title: <a onClick={() => navigate(provider.basePath)}>{provider.label}</a> },
        { title: `Account #${accountId}` },
        { title: `${profile?.nickname || "Profile"} (${profile?.game_name || ""})` },
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
            {/* Header section */}
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

                    {/* Controls bar */}
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
                                    style={{ width: isMobile ? "100%" : 210 }}
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

                        {profile?.supports_pull_sync && (
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

                        {importMenuItems.length > 0 && (
                            <Dropdown menu={{ items: importMenuItems }} trigger={["click"]}>
                                <Button icon={<ImportOutlined />}>
                                    <Space size={4}>
                                        Import
                                        <DownOutlined style={{ fontSize: 11 }} />
                                    </Space>
                                </Button>
                            </Dropdown>
                        )}

                        {banners.some((b) => b.total_pulls > 0) && (
                            <Button icon={<ShareAltOutlined />} onClick={() => setShareOpen(true)}>
                                {!isMobile && "Share"}
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
                                    : navigate(provider.basePath)
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
                <PullOperationProgressBanner
                    operation={activeOperation}
                    onDismiss={dismissOperation}
                />

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
                        accountId={accountId}
                        gameProfileId={gameProfileId}
                        supportsManualPulls={supportsManualPulls}
                        operationActive={operationActive}
                        onPullsChanged={handlePullsChanged}
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
                        {/* Banner Cards Grid (inspired by zzz.rng.moe) */}
                        <Row gutter={[16, 16]} align="stretch">
                            {banners.map((banner) => (
                                <Col key={banner.key} xs={24} md={12} xl={8}>
                                    <PullsBannerCard
                                        banner={banner}
                                        rarityConfig={rarityConfig}
                                        onViewDetails={handleSelectBanner}
                                    />
                                </Col>
                            ))}
                        </Row>

                        {/* Monthly Pulls Distribution Chart (inspired by paimon.moe) */}
                        <PullsMonthlyChart
                            monthlyTimeline={overview?.monthly_timeline || []}
                            rarityConfig={rarityConfig}
                        />
                    </Space>
                )}
            </div>

            {/* Provider-specific Import Modal (e.g. PowerShell for HoYoverse) */}
            {openExtraImporter && (
                <openExtraImporter.Modal
                    open
                    onClose={() => setOpenExtraImporterKey(null)}
                    profile={profile}
                    accountId={accountId}
                    profileId={gameProfileId}
                    onImportStarted={(op) => {
                        if (op) {
                            applyOperationUpdate(op);
                        }
                    }}
                />
            )}

            {/* Backup File Import Modal */}
            <BackupPullImportModal
                open={selectedBackupImporter !== null}
                importer={selectedBackupImporter}
                profile={profile}
                activeOperation={activeOperation}
                onUpload={(importerKey, file) =>
                    api
                        .gameProfiles()
                        .importBackupPulls(
                            accountId,
                            profile?.id ?? gameProfileId,
                            importerKey,
                            file
                        )
                }
                onClose={() => setSelectedBackupImporter(null)}
                onOperationStarted={(op) => {
                    if (op) {
                        applyOperationUpdate(op);
                    }
                }}
            />

            {/* Shareable image of the pulls (the banner on screen, or all of them) */}
            <PullsShareModal
                open={shareOpen}
                onClose={() => setShareOpen(false)}
                accountId={accountId}
                gameProfileId={profile?.id ?? gameProfileId}
                banners={banners}
                initialBannerKey={selectedBannerKey}
                rarityConfig={rarityConfig}
            />
        </div>
    );
}
