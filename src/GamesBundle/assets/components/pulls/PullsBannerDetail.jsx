import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { useGameProvider } from "@GamesBundle/components/GameProviderContext";
import DeclarePityModal from "@GamesBundle/components/pulls/DeclarePityModal";
import ManualSPullModal from "@GamesBundle/components/pulls/ManualSPullModal";
import { PullsRecentBadge, getPityColor } from "@GamesBundle/components/pulls/PullsBannerCard";
import {
    Row,
    Col,
    Card,
    Statistic,
    Space,
    Button,
    Table,
    Tag,
    Input,
    Typography,
    Empty,
    Grid,
    Tooltip,
    Dropdown,
    Popconfirm,
    App,
} from "antd";
import {
    SearchOutlined,
    CloseCircleOutlined,
    EllipsisOutlined,
    EditOutlined,
    PlusOutlined,
    UndoOutlined,
    DeleteOutlined,
    WarningOutlined,
    CheckCircleOutlined,
} from "@ant-design/icons";
import React, { useEffect, useState } from "react";

const { Text } = Typography;
const { useBreakpoint } = Grid;

// Neutral grey of unknown ("filler") pulls
const UNKNOWN_COLOR = "#8c8c8c";
const OPERATION_ACTIVE_HINT = "Not available while an import or sync is running";

function isFiller(record) {
    return record?.origin === "filler";
}

// S rows built from the official S-rank history: their pity and date are not the user's to change
function isOfficial(record) {
    return record?.origin === "official";
}

function hasValue(value) {
    return value !== null && value !== undefined;
}

// Label of free pulls (Endfield Urgent Recruitment), flagged by is_free on the row
const FREE_PULL_LABEL = "Urgent Recruitment";

// Free pull (Endfield Urgent Recruitment): listed with its own pity, but no pull number
function isFreePull(record) {
    return record?.is_free === true;
}

// Bonus entry that is not a pull (Endfield gifts, e.g. "Headhunting Dossier"): its label replaces the rarity
function getBonusLabel(record) {
    const label = record?.bonus_label;
    return typeof label === "string" && label.trim() !== "" ? label : null;
}

/**
 * Banner name with its rerun number ("#N"), as the official game page shows it.
 * The suffix is not repeated when the name already ends with it.
 */
function formatPoolName(name, poolVersion) {
    if (!name || !Number.isInteger(poolVersion) || poolVersion <= 0) {
        return name;
    }
    const suffix = ` #${poolVersion}`;
    return name.endsWith(suffix) ? name : `${name}${suffix}`;
}

// Date part of a "Y-m-d H:i:s" (or ISO) timestamp
function formatPoolDate(value) {
    return hasValue(value) && value !== "" ? String(value).split(/[ T]/)[0] : null;
}

/**
 * Insertion window of an S row or of the banner open window, as expected by ManualSPullModal.
 */
function pickInsertWindow(source) {
    return {
        insert_after: source?.insert_after ?? null,
        insert_before: source?.insert_before ?? null,
        suggested_at: source?.suggested_at ?? null,
        has_room: source?.has_room ?? true,
    };
}

function getInitials(name) {
    if (!name) return "?";
    const words = name.trim().split(/\s+/);
    if (words.length === 1) {
        return words[0].substring(0, 2).toUpperCase();
    }
    return (words[0][0] + words[1][0]).toUpperCase();
}

function PullItemThumbnail({ iconUrl, name, borderColor, placeholder }) {
    const [imgError, setImgError] = useState(false);

    useEffect(() => {
        setImgError(false);
    }, [iconUrl]);

    const showImage = !placeholder && Boolean(iconUrl) && !imgError;

    return (
        <div
            style={{
                width: 32,
                height: 32,
                borderRadius: "50%",
                overflow: "hidden",
                flexShrink: 0,
                display: "inline-flex",
                alignItems: "center",
                justifyContent: "center",
                border: `1.5px solid ${borderColor || "rgba(140, 140, 140, 0.3)"}`,
                background: "rgba(0, 0, 0, 0.04)",
                fontSize: 11,
                fontWeight: 700,
                color: borderColor || "inherit",
            }}
        >
            {showImage ? (
                <img
                    src={iconUrl}
                    alt={name}
                    loading="lazy"
                    onError={() => setImgError(true)}
                    style={{
                        width: "100%",
                        height: "100%",
                        objectFit: "cover",
                        display: "block",
                    }}
                />
            ) : (
                placeholder ?? getInitials(name)
            )}
        </div>
    );
}

/**
 * "⋯" menu of an S row. Confirmations are a controlled Popconfirm anchored on the button,
 * because a Popconfirm inside a menu item would unmount when the menu closes.
 */
function PullRowActions({ record, sLabel, disabled, allowAddEarlier = true, onCorrectPity, onAddEarlier, onRemoveCorrection, onDelete }) {
    // Kind is kept after closing so the Popconfirm text doesn't change during its close animation
    const [confirmKind, setConfirmKind] = useState("remove");
    const [confirmOpen, setConfirmOpen] = useState(false);
    const isManual = record.origin === "manual";

    // An import or sync starting while a confirmation is open cancels it
    useEffect(() => {
        if (disabled) {
            setConfirmOpen(false);
        }
    }, [disabled]);
    const hasCorrection = hasValue(record.declared_pity);

    const menuItems = [{ key: "correct", icon: <EditOutlined />, label: "Correct pity…" }];
    if (allowAddEarlier) {
        menuItems.push({ key: "add", icon: <PlusOutlined />, label: `Add earlier ${sLabel}…` });
    }
    if (hasCorrection && !isManual) {
        menuItems.push({ key: "remove", icon: <UndoOutlined />, label: "Remove correction" });
    }
    if (isManual) {
        menuItems.push({ type: "divider" });
        menuItems.push({ key: "delete", icon: <DeleteOutlined />, label: "Delete", danger: true });
    }

    const handleMenuClick = ({ key, domEvent }) => {
        // Keep the click away from the Popconfirm trigger wrapping the button
        domEvent?.stopPropagation();
        if (key === "correct") {
            onCorrectPity(record);
        } else if (key === "add") {
            onAddEarlier(record);
        } else if (key === "remove" || key === "delete") {
            setConfirmKind(key);
            setConfirmOpen(true);
        }
    };

    const isDelete = confirmKind === "delete";

    if (disabled) {
        return (
            <Tooltip title={OPERATION_ACTIVE_HINT}>
                <Button type="text" size="small" icon={<EllipsisOutlined />} disabled aria-label="Pull actions" />
            </Tooltip>
        );
    }

    return (
        <Popconfirm
            open={confirmOpen}
            onOpenChange={(nextOpen) => {
                // Opening happens only from the menu
                if (!nextOpen) {
                    setConfirmOpen(false);
                }
            }}
            placement="left"
            title={isDelete ? `Delete this manual ${sLabel}?` : "Remove the pity correction?"}
            description={isDelete
                ? "Its unknown pulls are removed too."
                : "The unknown pulls added for it are removed."}
            okText={isDelete ? "Delete" : "Remove"}
            okButtonProps={{ danger: true }}
            onConfirm={() =>
                (isDelete ? onDelete(record) : onRemoveCorrection(record)).finally(() => setConfirmOpen(false))
            }
            onCancel={() => setConfirmOpen(false)}
        >
            <span style={{ display: "inline-block" }}>
                <Dropdown menu={{ items: menuItems, onClick: handleMenuClick }} trigger={["click"]}>
                    <Button type="text" size="small" icon={<EllipsisOutlined />} aria-label="Pull actions" />
                </Dropdown>
            </span>
        </Popconfirm>
    );
}

export default function PullsBannerDetail({
    banner,
    rarityConfig,
    items,
    total,
    page,
    pageSize,
    loading,
    selectedRarities,
    search,
    onToggleRarity,
    onSearchChange,
    onSearchSubmit,
    onResetFilters,
    onTableChange,
    accountId,
    gameProfileId,
    supportsManualPulls = false,
    operationActive = false,
    onPullsChanged,
}) {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;
    const api = useBackendApi();
    const provider = useGameProvider();
    const { message } = App.useApp();
    const officialLabel = provider.officialPullLabel || "official";

    // Targets are kept after closing so the modals don't change content during the close animation.
    // S row whose pity is being corrected (DeclarePityModal)
    const [declareTarget, setDeclareTarget] = useState(null);
    const [declareOpen, setDeclareOpen] = useState(false);
    // { bannerKey, anchor, window } snapshot for ManualSPullModal
    const [manualTarget, setManualTarget] = useState(null);
    const [manualOpen, setManualOpen] = useState(false);

    const openDeclareModal = (record) => {
        setDeclareTarget(record);
        setDeclareOpen(true);
    };
    const openManualModal = (target) => {
        setManualTarget(target);
        setManualOpen(true);
    };

    const sRarity = rarityConfig?.s?.rarity ?? 5;
    const aRarity = rarityConfig?.a?.rarity ?? 4;
    const bRarity = rarityConfig?.b?.rarity ?? 3;
    const sLabel = rarityConfig?.s?.label || "S";
    const aLabel = rarityConfig?.a?.label || "A";
    const sShort = rarityConfig?.s?.short || "S";
    const aShort = rarityConfig?.a?.short || "A";
    const bShort = rarityConfig?.b?.short || "B";
    // A banner may count its own unit (e.g. NTE "Rolls" vs "Arcs"); fallback to the game-wide label
    const pullLabel = banner?.pull_label || rarityConfig?.pull_label || "Pulls";
    const pullSingularLabel = banner?.pull_singular_label || rarityConfig?.pull_singular_label || "Pull";

    const cardStyles = {
        body: {
            padding: isMobile ? "12px 14px" : "18px 22px",
        },
    };

    const sMaxPity = banner?.s_max_pity || 90;

    const rarityToggles = [
        { rarity: sRarity, label: sShort, color: "#faad14", textColor: "#000" },
        { rarity: aRarity, label: aShort, color: "#b37feb", textColor: "#fff" },
        { rarity: bRarity, label: bShort, color: "#595959", textColor: "#fff" },
    ];
    // Unknown ("filler") pulls are stored with rarity 0; the pill is useless where they can't exist
    if (supportsManualPulls || (banner?.unknown_count ?? 0) > 0 || selectedRarities.includes(0)) {
        rarityToggles.push({
            rarity: 0,
            label: "?",
            color: UNKNOWN_COLOR,
            textColor: "#fff",
            tooltip: "Unknown pulls: placeholders for the missing history",
        });
    }

    // S rows that reset the pity: only they carry has_room (S items without pity, e.g. NTE, don't)
    const isPitySRow = (record) =>
        !isFiller(record) && record.rarity >= sRarity && typeof record.has_room === "boolean";

    const handleRemoveCorrection = (record) =>
        api
            .withErrorHandling()
            .gameProfiles()
            .declarePullPity(accountId, gameProfileId, record.id, null)
            .then(() => {
                message.success("Pity correction removed");
                onPullsChanged?.();
            })
            .catch(() => {});

    const handleDeleteManual = (record) =>
        api
            .withErrorHandling()
            .gameProfiles()
            .deleteManualPull(accountId, gameProfileId, record.id)
            .then(() => {
                message.success(`Manual ${sLabel} deleted`);
                onPullsChanged?.();
            })
            .catch(() => {});

    const hasRollLabels = (items || []).some((item) => "roll_label" in item);

    const getRowColor = (rarity) => {
        if (rarity >= sRarity) return "#d48806";
        if (rarity === aRarity) return "#722ed1";
        return undefined;
    };
    // Bonus entries have no rarity colour, even when they carry a rarity (e.g. a gifted weapon)
    const getRecordColor = (record) => (getBonusLabel(record) ? undefined : getRowColor(record.rarity));

    const columns = [
        {
            title: "#",
            dataIndex: "pull_number",
            key: "pull_number",
            width: 70,
            render: (num, record) => (num === null || num === undefined ? (
                // Bonus rows (e.g. NTE Points Gift) are listed but are not pulls
                <Text type="secondary">—</Text>
            ) : isFiller(record) ? (
                <Text type="secondary">{num}</Text>
            ) : (
                <Text strong style={{ color: getRecordColor(record) }}>
                    {num}
                </Text>
            )),
        },
        // Optional per-row roll outcome (NTE boards: dice value, Points Gift, Slumberland)
        ...(hasRollLabels ? [{
            title: "Roll",
            dataIndex: "roll_label",
            key: "roll_label",
            width: 110,
            render: (label) => {
                if (label === "Points Gift") {
                    return <Tag color="purple" style={{ margin: 0 }}>Points Gift</Tag>;
                }
                if (label === "Slumberland") {
                    return <Tag color="geekblue" style={{ margin: 0 }}>Slumberland</Tag>;
                }
                return <Text type="secondary">{label ?? "—"}</Text>;
            },
        }] : []),
        {
            title: "Item",
            dataIndex: "name",
            key: "name",
            render: (name, record) => {
                if (isFiller(record)) {
                    return (
                        <Space size={8} align="center" wrap>
                            <PullItemThumbnail placeholder="?" borderColor={UNKNOWN_COLOR} />
                            <Text italic style={{ color: UNKNOWN_COLOR }}>
                                Unknown pull
                            </Text>
                            <Tag style={{ margin: 0 }}>?</Tag>
                        </Space>
                    );
                }

                const bonusLabel = getBonusLabel(record);
                // Bonus entries are styled as a plain row, whatever their rarity
                const isTop = !bonusLabel && record.rarity >= sRarity;
                const isSec = !bonusLabel && record.rarity === aRarity;
                const tagColor = isTop ? "gold" : isSec ? "purple" : "default";
                const rankText = isTop ? sShort : isSec ? aShort : bShort;
                const rowColor = getRecordColor(record);

                return (
                    <Space size={8} align="center" wrap>
                        <PullItemThumbnail
                            iconUrl={record.icon_url}
                            name={name}
                            borderColor={rowColor}
                        />
                        <Text
                            strong={isTop || isSec}
                            style={{
                                color: rowColor,
                                fontSize: isTop ? 15 : 14,
                            }}
                        >
                            {name}
                        </Text>
                        {bonusLabel ? (
                            // The label already is the name of most bonus entries (e.g. "Headhunting Dossier")
                            <Tag color="magenta" style={{ margin: 0 }}>
                                {bonusLabel === name ? "Bonus" : bonusLabel}
                            </Tag>
                        ) : (
                            <Tag color={tagColor} style={{ margin: 0 }}>
                                {rankText}
                            </Tag>
                        )}
                        {record.is_up === true && (
                            <Tooltip title="Rate-up">
                                <Tag color="volcano" style={{ margin: 0, fontSize: 11, fontWeight: 700 }}>
                                    UP
                                </Tag>
                            </Tooltip>
                        )}
                        {isFreePull(record) && (
                            <Tooltip title={`Free ${pullSingularLabel.toLowerCase()}`}>
                                <Tag color="green" style={{ margin: 0, fontSize: 11 }}>
                                    {FREE_PULL_LABEL}
                                </Tag>
                            </Tooltip>
                        )}
                        {record.origin === "manual" && (
                            <Tooltip title="Added manually">
                                <Tag color="blue" style={{ margin: 0, fontSize: 11 }}>
                                    manual
                                </Tag>
                            </Tooltip>
                        )}
                        {isOfficial(record) && (
                            <Tooltip title={`From the official ${officialLabel} history: pity and date are exact`}>
                                <Tag color="cyan" style={{ margin: 0, fontSize: 11 }}>
                                    {officialLabel}
                                </Tag>
                            </Tooltip>
                        )}
                        {record.item_type && !(bonusLabel && !record.item_id) && (
                            // A bonus entry without an item (e.g. a dossier) is no operator or weapon
                            <Text type="secondary" style={{ fontSize: 12 }}>
                                ({record.item_type_label ?? record.item_type})
                            </Text>
                        )}
                    </Space>
                );
            },
        },
        {
            title: "Pity",
            dataIndex: "pity",
            key: "pity",
            width: supportsManualPulls ? 120 : 90,
            render: (pity, record) => {
                // No pity value: unknown pulls, bonus rows and rewards that do not reset pity (e.g. S items)
                if (isFiller(record) || pity === null || pity === undefined) {
                    return <Text type="secondary">—</Text>;
                }
                // Bonus entries have no rarity colour
                if (getBonusLabel(record)) {
                    return <Text type="secondary">{pity}</Text>;
                }
                if (record.rarity >= sRarity) {
                    const scheme = getPityColor(pity, sMaxPity);
                    const pityTag = (
                        <Tag
                            style={{
                                backgroundColor: scheme.badgeBg,
                                color: "#fff",
                                borderColor: scheme.border,
                                fontWeight: 700,
                                marginInlineEnd: 0,
                            }}
                        >
                            {pity}
                        </Tag>
                    );
                    const declared = record.declared_pity;
                    if (!hasValue(declared)) {
                        return pityTag;
                    }
                    const recorded = record.recorded_pity ?? 0;
                    const unknown = Math.max(0, declared - recorded);
                    return (
                        <Space size={4} align="center">
                            {pityTag}
                            {isOfficial(record) ? (
                                <Tooltip title={`Official pity ${declared} (${recorded} recorded + ${unknown} unknown)`}>
                                    <CheckCircleOutlined style={{ color: "#13c2c2", fontSize: 12 }} />
                                </Tooltip>
                            ) : (
                                <Tooltip title={`Declared pity ${declared} (${recorded} recorded + ${unknown} unknown)`}>
                                    <EditOutlined style={{ color: "#1677ff", fontSize: 12 }} />
                                </Tooltip>
                            )}
                            {pity !== declared && (
                                <Tooltip title="Recorded pulls exceed the declared pity">
                                    <WarningOutlined style={{ color: "#faad14" }} />
                                </Tooltip>
                            )}
                        </Space>
                    );
                }
                if (record.rarity === aRarity) {
                    return (
                        <Text strong style={{ color: "#722ed1" }}>
                            {pity}
                        </Text>
                    );
                }
                return <Text type="secondary">{pity}</Text>;
            },
        },
        {
            title: "Banner",
            dataIndex: "gacha_name",
            key: "gacha_name",
            responsive: ["md"],
            render: (text, record) => (
                <Text
                    type={isFiller(record) ? "secondary" : undefined}
                    style={{ color: getRecordColor(record) || undefined, opacity: 0.85 }}
                >
                    {/* Rerun number (Endfield pool_version) appended as "#N" */}
                    {formatPoolName(text || banner?.name, record.pool_version) || "—"}
                </Text>
            ),
        },
        {
            title: "Time",
            dataIndex: "pulled_at",
            key: "pulled_at",
            width: isMobile ? 135 : 180,
            render: (text, record) => (isFiller(record) ? (
                <Tooltip title="Estimated date">
                    <Text type="secondary" style={{ fontSize: isMobile ? 12 : 13 }}>
                        ≈ {text}
                    </Text>
                </Tooltip>
            ) : (
                <Text style={{ color: getRecordColor(record) || "inherit", fontSize: isMobile ? 12 : 13 }}>
                    {text}
                </Text>
            )),
        },
        ...(supportsManualPulls ? [{
            title: "",
            key: "actions",
            width: 44,
            align: "center",
            // Official S: no correction, no earlier S before them (the official history lists every S), no deletion
            render: (_, record) => (isPitySRow(record) && !isOfficial(record) ? (
                <PullRowActions
                    record={record}
                    sLabel={sLabel}
                    disabled={operationActive}
                    // No earlier S where the pity resets with each banner (allow_add_s, Endfield only)
                    allowAddEarlier={banner?.allow_add_s !== false}
                    onCorrectPity={openDeclareModal}
                    onAddEarlier={(anchor) => openManualModal({
                        bannerKey: banner?.key,
                        anchor,
                        window: pickInsertWindow(anchor),
                    })}
                    onRemoveCorrection={handleRemoveCorrection}
                    onDelete={handleDeleteManual}
                />
            ) : null),
        }] : []),
    ];

    const recentSPulls = banner?.recent_s_pulls || [];

    // Banner the pity counters refer to, for groups whose pity resets per banner (Endfield)
    const pityPoolName = typeof banner?.pity_pool_name === "string" && banner.pity_pool_name.trim() !== ""
        ? banner.pity_pool_name
        : null;
    // Only the S card grows with the pity banner line: stretch the KPI cards to keep the row even
    const kpiCardStyle = pityPoolName ? { height: "100%" } : undefined;

    // Per-banner counters of a group (Endfield, NTE): one row per pool, the same pool may come back as a rerun
    const poolRows = (Array.isArray(banner?.pools) ? banner.pools : []).map((pool, index) => ({
        ...pool,
        key: `${pool?.pool_id ?? "pool"}-${pool?.pool_version ?? 0}-${index}`,
    }));
    const poolsHaveSPity = poolRows.some((pool) => hasValue(pool.s_pity));
    const poolsHaveAPity = poolRows.some((pool) => hasValue(pool.a_pity));
    const formatCount = (value) => (typeof value === "number" ? value.toLocaleString() : "—");
    const poolColumns = [
        {
            title: "Banner",
            dataIndex: "pool_name",
            key: "pool_name",
            render: (name, pool) => <Text strong>{formatPoolName(name, pool.pool_version) || "—"}</Text>,
        },
        {
            title: pullLabel,
            dataIndex: "total_pulls",
            key: "total_pulls",
            render: (value) => <Text style={{ color: "#1677ff" }}>{formatCount(value)}</Text>,
        },
        {
            title: sShort,
            dataIndex: "s_count",
            key: "s_count",
            render: (value) => <Text style={{ color: "#fa8c16" }}>{formatCount(value)}</Text>,
        },
        ...(poolsHaveSPity ? [{
            title: `${sShort} Pity`,
            dataIndex: "s_pity",
            key: "s_pity",
            render: (value) => (hasValue(value)
                ? <Text strong style={{ color: "#fa8c16" }}>{value}</Text>
                : <Text type="secondary">—</Text>),
        }] : []),
        ...(poolsHaveAPity ? [{
            title: `${aShort} Pity`,
            dataIndex: "a_pity",
            key: "a_pity",
            render: (value) => (hasValue(value)
                ? <Text strong style={{ color: "#722ed1" }}>{value}</Text>
                : <Text type="secondary">—</Text>),
        }] : []),
        {
            title: "Period",
            key: "period",
            render: (_, pool) => {
                const first = formatPoolDate(pool.first_pulled_at);
                const last = formatPoolDate(pool.last_pulled_at);
                if (!first && !last) {
                    return <Text type="secondary">—</Text>;
                }
                return (
                    <Text type="secondary" style={{ fontSize: 13, whiteSpace: "nowrap" }}>
                        {first && last && first !== last ? `${first} → ${last}` : first || last}
                    </Text>
                );
            },
        },
    ];

    // "Add missing S" goes before the chronologically first S (banner.oldest_s; recent_s_pulls is
    // newest-first and may be capped, so its last element is only a fallback).
    // Without any S the new pull goes in the banner open window (anchor_id = null).
    const oldestS = banner?.oldest_s
        ?? (recentSPulls.length > 0 ? recentSPulls[recentSPulls.length - 1] : null);
    // has_room is irrelevant for the open window (false for an empty banner): a suggested date is enough
    const openWindowUsable = oldestS !== null || hasValue(banner?.open_window?.suggested_at);

    const openAddMissingS = () => {
        openManualModal({
            bannerKey: banner?.key,
            anchor: oldestS,
            window: pickInsertWindow(oldestS ?? banner?.open_window),
        });
    };
    // Before an official S nothing is missing: the official history lists every S of the banner
    const oldestSIsOfficial = isOfficial(oldestS);
    const addMissingSHint = operationActive
        ? OPERATION_ACTIVE_HINT
        : oldestSIsOfficial
            ? `The official ${officialLabel} history lists every ${sLabel} of this banner`
            : !openWindowUsable
                ? `No valid date to place a ${sLabel} in this banner`
                : undefined;

    const closeManualModal = () => setManualOpen(false);
    const closeDeclareModal = () => setDeclareOpen(false);

    return (
        <Space orientation="vertical" size={isMobile ? "middle" : "large"} style={{ width: "100%" }}>
            {/* Top KPI Summary Cards (like zzz.rng.moe detail header) */}
            <Row gutter={[16, 16]}>
                <Col xs={12} sm={8} md={4}>
                    <Card variant="borderless" styles={cardStyles} style={kpiCardStyle}>
                        <Statistic
                            title={`Total ${pullLabel}`}
                            value={banner?.total_pulls || 0}
                            styles={{ content: { color: "#1677ff", fontWeight: "bold" } }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={8} md={5}>
                    <Card variant="borderless" styles={cardStyles} style={kpiCardStyle}>
                        <Statistic
                            title={`${sLabel} ${pullLabel}`}
                            value={banner?.s_count || 0}
                            suffix={
                                <Text type="secondary" style={{ fontSize: 13 }}>
                                    (Pity: {banner?.s_pity || 0}/{banner?.s_max_pity || 90})
                                </Text>
                            }
                            styles={{ content: { color: "#fa8c16", fontWeight: "bold" } }}
                        />
                        {pityPoolName && (
                            <Text
                                type="secondary"
                                ellipsis={{ tooltip: `Pity in ${pityPoolName}` }}
                                style={{ display: "block", fontSize: 12 }}
                            >
                                Pity in {pityPoolName}
                            </Text>
                        )}
                    </Card>
                </Col>
                <Col xs={12} sm={8} md={5}>
                    <Card variant="borderless" styles={cardStyles} style={kpiCardStyle}>
                        <Statistic
                            title={`${aLabel} ${pullLabel}`}
                            value={banner?.a_count || 0}
                            suffix={
                                <Text type="secondary" style={{ fontSize: 13 }}>
                                    (Pity: {banner?.a_pity || 0}/{banner?.a_max_pity || 10})
                                </Text>
                            }
                            styles={{ content: { color: "#722ed1", fontWeight: "bold" } }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={12} md={5}>
                    <Card variant="borderless" styles={cardStyles} style={kpiCardStyle}>
                        <Statistic
                            title={`Avg ${sLabel} Pity`}
                            value={banner?.avg_s_pity || 0}
                            precision={2}
                            styles={{ content: { color: "#d48806", fontWeight: "bold" } }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={12} md={5}>
                    <Card variant="borderless" styles={cardStyles} style={kpiCardStyle}>
                        <Statistic
                            title={`Avg ${aLabel} Pity`}
                            value={banner?.avg_a_pity || 0}
                            precision={2}
                            styles={{ content: { color: "#531dab", fontWeight: "bold" } }}
                        />
                    </Card>
                </Col>
            </Row>

            {/* Recent S / 5* / 6* Signals Section */}
            <Card
                title={`Recent ${sLabel} ${pullLabel} (${recentSPulls.length})`}
                // allow_add_s: false on banners whose pity resets with each banner (Endfield), absent elsewhere
                extra={supportsManualPulls && banner?.allow_add_s !== false ? (
                    <Tooltip title={addMissingSHint}>
                        <Button
                            size="small"
                            icon={<PlusOutlined />}
                            disabled={operationActive || oldestSIsOfficial || !openWindowUsable || !banner?.key}
                            onClick={openAddMissingS}
                        >
                            {isMobile ? "Add" : `Add missing ${sLabel}`}
                        </Button>
                    </Tooltip>
                ) : undefined}
                styles={{
                    header: { padding: isMobile ? "12px 14px" : "14px 20px" },
                    body: { padding: isMobile ? "12px 14px" : "18px 20px" },
                }}
            >
                {recentSPulls.length > 0 ? (
                    <div style={{ display: "flex", flexWrap: "wrap", gap: 14 }}>
                        {recentSPulls.map((pull) => (
                            <PullsRecentBadge
                                key={pull.id}
                                pull={pull}
                                maxPity={banner?.s_max_pity || 90}
                                pullSingularLabel={pullSingularLabel}
                            />
                        ))}
                    </div>
                ) : (
                    <Empty description={`No ${sLabel} ${pullLabel} found in this banner`} />
                )}
            </Card>

            {/* Per-banner counters of the group (Endfield pools), only when the backend sends them */}
            {poolRows.length > 0 && (
                <Card
                    title={`Banners (${poolRows.length})`}
                    styles={{
                        header: { padding: isMobile ? "12px 14px" : "14px 20px" },
                        body: { padding: isMobile ? "12px 8px" : "18px 20px" },
                    }}
                >
                    <Table
                        size="small"
                        columns={poolColumns}
                        dataSource={poolRows}
                        pagination={false}
                        scroll={{ x: isMobile ? 520 : undefined }}
                    />
                </Card>
            )}

            {/* Detailed Pulls Table with Rarity Pill Toggles (B / 3* hidden by default) */}
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
                        {/* Rarity Filter Pills (inspired by zzz.rng.moe) */}
                        <Space size={8} wrap>
                            <Text strong style={{ marginRight: 4 }}>
                                Rarity Filter:
                            </Text>
                            {rarityToggles.map((rt) => {
                                const active = selectedRarities.includes(rt.rarity);
                                const pill = (
                                    <Button
                                        key={rt.rarity}
                                        size="small"
                                        shape="round"
                                        onClick={() => onToggleRarity(rt.rarity)}
                                        style={{
                                            fontWeight: 700,
                                            minWidth: 42,
                                            backgroundColor: active ? rt.color : "rgba(0, 0, 0, 0.06)",
                                            borderColor: active ? rt.color : "transparent",
                                            color: active ? rt.textColor : "rgba(0, 0, 0, 0.45)",
                                            opacity: active ? 1 : 0.65,
                                        }}
                                    >
                                        {rt.label}
                                    </Button>
                                );
                                return rt.tooltip ? (
                                    <Tooltip key={rt.rarity} title={rt.tooltip}>
                                        {pill}
                                    </Tooltip>
                                ) : pill;
                            })}
                        </Space>

                        {/* Search Input */}
                        <Space wrap size="small" style={{ flex: isMobile ? "1 1 100%" : "0 0 auto" }}>
                            <Input
                                placeholder="Search item..."
                                value={search}
                                onChange={(e) => onSearchChange(e.target.value)}
                                onPressEnter={onSearchSubmit}
                                prefix={<SearchOutlined />}
                                allowClear
                                style={{ width: isMobile ? "100%" : 220 }}
                            />
                            <Button type="primary" onClick={onSearchSubmit}>
                                Search
                            </Button>
                            {search && (
                                <Button icon={<CloseCircleOutlined />} onClick={onResetFilters}>
                                    Reset
                                </Button>
                            )}
                        </Space>
                    </div>
                }
            >
                <Table
                    rowKey="id"
                    size="small"
                    columns={columns}
                    dataSource={items}
                    loading={loading}
                    // Free pulls (Endfield Urgent Recruitment) are muted; every other row gets no extra props
                    onRow={(record) => (isFreePull(record) ? { style: { opacity: 0.7 } } : {})}
                    onChange={onTableChange}
                    scroll={{ x: isMobile ? (supportsManualPulls ? 480 : 420) : undefined }}
                    pagination={{
                        current: page,
                        pageSize: pageSize,
                        total: total,
                        responsive: true,
                        simple: isMobile,
                        showSizeChanger: !isMobile,
                        pageSizeOptions: [10, 15, 25, 50, 100],
                        showTotal: isMobile
                            ? undefined
                            : (totalCount, range) => `${range[0]}-${range[1]} of ${totalCount} ${pullLabel.toLowerCase()}`,
                        scrollToFirstRowOnChange: false,
                    }}
                />
            </Card>

            {supportsManualPulls && (
                <>
                    <ManualSPullModal
                        open={manualOpen && manualTarget !== null}
                        onClose={closeManualModal}
                        onSaved={() => {
                            closeManualModal();
                            onPullsChanged?.();
                        }}
                        accountId={accountId}
                        gameProfileId={gameProfileId}
                        bannerKey={manualTarget?.bannerKey}
                        anchor={manualTarget?.anchor ?? null}
                        window={manualTarget?.window ?? null}
                        sMaxPity={sMaxPity}
                        rarityConfig={rarityConfig}
                    />
                    <DeclarePityModal
                        open={declareOpen && declareTarget !== null}
                        onClose={closeDeclareModal}
                        onSaved={() => {
                            closeDeclareModal();
                            onPullsChanged?.();
                        }}
                        accountId={accountId}
                        gameProfileId={gameProfileId}
                        pull={declareTarget}
                        sMaxPity={sMaxPity}
                        rarityConfig={rarityConfig}
                    />
                </>
            )}
        </Space>
    );
}
