import { PullsRecentBadge, getPityColor } from "@HoyoverseBundle/components/pulls/PullsBannerCard";
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
} from "antd";
import { SearchOutlined, CloseCircleOutlined } from "@ant-design/icons";
import React, { useEffect, useState } from "react";

const { Text } = Typography;
const { useBreakpoint } = Grid;

function getInitials(name) {
    if (!name) return "?";
    const words = name.trim().split(/\s+/);
    if (words.length === 1) {
        return words[0].substring(0, 2).toUpperCase();
    }
    return (words[0][0] + words[1][0]).toUpperCase();
}

function PullItemThumbnail({ iconUrl, name, borderColor }) {
    const [imgError, setImgError] = useState(false);

    useEffect(() => {
        setImgError(false);
    }, [iconUrl]);

    const showImage = Boolean(iconUrl) && !imgError;

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
                getInitials(name)
            )}
        </div>
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
}) {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;

    const sRarity = rarityConfig?.s?.rarity ?? 5;
    const aRarity = rarityConfig?.a?.rarity ?? 4;
    const bRarity = rarityConfig?.b?.rarity ?? 3;
    const sLabel = rarityConfig?.s?.label || "5★";
    const aLabel = rarityConfig?.a?.label || "4★";
    const sShort = rarityConfig?.s?.short || "5★";
    const aShort = rarityConfig?.a?.short || "4★";
    const bShort = rarityConfig?.b?.short || "3★";
    const pullLabel = rarityConfig?.pull_label || "Pulls";
    const pullSingularLabel = rarityConfig?.pull_singular_label || "Pull";

    const cardStyles = {
        body: {
            padding: isMobile ? "12px 14px" : "18px 22px",
        },
    };

    const rarityToggles = [
        { rarity: sRarity, label: sShort, color: "#faad14", textColor: "#000" },
        { rarity: aRarity, label: aShort, color: "#b37feb", textColor: "#fff" },
        { rarity: bRarity, label: bShort, color: "#595959", textColor: "#fff" },
    ];

    const getRowColor = (rarity) => {
        if (rarity >= sRarity) return "#d48806";
        if (rarity === aRarity) return "#722ed1";
        return undefined;
    };

    const columns = [
        {
            title: "#",
            dataIndex: "pull_number",
            key: "pull_number",
            width: 70,
            render: (num, record) => (
                <Text strong style={{ color: getRowColor(record.rarity) }}>
                    {num}
                </Text>
            ),
        },
        {
            title: "Item",
            dataIndex: "name",
            key: "name",
            render: (name, record) => {
                const isTop = record.rarity >= sRarity;
                const isSec = record.rarity === aRarity;
                const tagColor = isTop ? "gold" : isSec ? "purple" : "default";
                const rankText = isTop ? sShort : isSec ? aShort : bShort;
                const rowColor = getRowColor(record.rarity);

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
                        <Tag color={tagColor} style={{ margin: 0 }}>
                            {rankText}
                        </Tag>
                        {record.item_type && (
                            <Text type="secondary" style={{ fontSize: 12 }}>
                                ({record.item_type})
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
            width: 90,
            render: (pity, record) => {
                if (record.rarity >= sRarity) {
                    const scheme = getPityColor(pity, banner?.s_max_pity || 90);
                    return (
                        <Tag
                            style={{
                                backgroundColor: scheme.badgeBg,
                                color: "#fff",
                                borderColor: scheme.border,
                                fontWeight: 700,
                            }}
                        >
                            {pity}
                        </Tag>
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
                <Text style={{ color: getRowColor(record.rarity) || "inherit", opacity: 0.85 }}>
                    {text || banner?.name || "—"}
                </Text>
            ),
        },
        {
            title: "Time",
            dataIndex: "pulled_at",
            key: "pulled_at",
            width: isMobile ? 135 : 180,
            render: (text, record) => (
                <Text style={{ color: getRowColor(record.rarity) || "inherit", fontSize: isMobile ? 12 : 13 }}>
                    {text}
                </Text>
            ),
        },
    ];

    const recentSPulls = banner?.recent_s_pulls || [];

    return (
        <Space orientation="vertical" size={isMobile ? "middle" : "large"} style={{ width: "100%" }}>
            {/* Top KPI Summary Cards (like zzz.rng.moe detail header) */}
            <Row gutter={[16, 16]}>
                <Col xs={12} sm={8} md={4}>
                    <Card variant="borderless" styles={cardStyles}>
                        <Statistic
                            title={`Total ${pullLabel}`}
                            value={banner?.total_pulls || 0}
                            styles={{ content: { color: "#1677ff", fontWeight: "bold" } }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={8} md={5}>
                    <Card variant="borderless" styles={cardStyles}>
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
                    </Card>
                </Col>
                <Col xs={12} sm={8} md={5}>
                    <Card variant="borderless" styles={cardStyles}>
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
                    <Card variant="borderless" styles={cardStyles}>
                        <Statistic
                            title={`Avg ${sLabel} Pity`}
                            value={banner?.avg_s_pity || 0}
                            precision={2}
                            styles={{ content: { color: "#d48806", fontWeight: "bold" } }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={12} md={5}>
                    <Card variant="borderless" styles={cardStyles}>
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
                                return (
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
                            })}
                        </Space>

                        {/* Search Input */}
                        <Space wrap size="small" style={{ flex: isMobile ? "1 1 100%" : "0 0 auto" }}>
                            <Input
                                placeholder="Search item name..."
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
                    onChange={onTableChange}
                    scroll={{ x: isMobile ? 420 : undefined }}
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
        </Space>
    );
}
