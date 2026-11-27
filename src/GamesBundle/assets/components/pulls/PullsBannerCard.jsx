import { useGameProvider } from "@GamesBundle/components/GameProviderContext";
import PullItemImage, { usePreviewTooltip } from "@GamesBundle/components/pulls/PullItemImage";
import { Card, Button, Space, Typography, Tooltip, Progress } from "antd";
import { RightOutlined, RocketOutlined } from "@ant-design/icons";
import React from "react";

const { Text, Title } = Typography;

// Label of free pulls (Endfield Urgent Recruitment), flagged by is_free on the row
const FREE_PULL_LABEL = "Urgent Recruitment";

/**
 * Computes color for a pull pity badge (green for early, gold/orange for mid/soft, red for late/hard pity).
 */
export function getPityColor(pity, maxPity = 90) {
    const ratio = pity / maxPity;
    if (ratio <= 0.45) {
        return { border: "#52c41a", bg: "rgba(82, 196, 26, 0.14)", badgeBg: "#389e0d", text: "#52c41a" };
    }
    if (ratio <= 0.78) {
        return { border: "#faad14", bg: "rgba(250, 173, 20, 0.14)", badgeBg: "#d48806", text: "#faad14" };
    }
    return { border: "#ff4d4f", bg: "rgba(255, 77, 79, 0.14)", badgeBg: "#cf1322", text: "#ff4d4f" };
}

export function PullsRecentBadge({ pull, maxPity = 90, pullSingularLabel = "Pull" }) {
    const colorScheme = getPityColor(pull.pity, maxPity);
    // Details on hover, artwork on click
    const { tooltipProps, onPreviewOpenChange } = usePreviewTooltip();
    // Manual S (added by the user), official S (from the official S-rank history) and S with a declared pity
    const isManual = pull.origin === "manual";
    const isOfficial = pull.origin === "official";
    const officialLabel = useGameProvider().officialPullLabel || "official";
    const hasDeclaredPity = pull.declared_pity !== null && pull.declared_pity !== undefined;
    // Optional flags: rate-up S (any provider) and free pull (Endfield), which has no pull number
    const isUp = pull.is_up === true;
    const isFree = pull.is_free === true;
    const hasPullNumber = pull.pull_number !== null && pull.pull_number !== undefined;
    const pityDetail = isFree
        ? ` (${FREE_PULL_LABEL})`
        : hasPullNumber
            ? ` (${pullSingularLabel} #${pull.pull_number})`
            : "";

    return (
        <Tooltip
            {...tooltipProps}
            title={
                <div style={{ fontSize: 12 }}>
                    <div style={{ fontWeight: 600 }}>{pull.name}</div>
                    <div>Pity: {pull.pity}{pityDetail}</div>
                    {isUp && <div>Rate-up</div>}
                    {hasDeclaredPity && !isOfficial && <div>Declared pity: {pull.declared_pity}</div>}
                    {isManual && <div>Added manually</div>}
                    {isOfficial && <div>From the official {officialLabel} history</div>}
                    <div style={{ opacity: 0.8 }}>{pull.pulled_at}</div>
                </div>
            }
        >
            <div
                style={{
                    display: "inline-flex",
                    flexDirection: "column",
                    alignItems: "center",
                    width: 68,
                    cursor: "default",
                }}
            >
                <div
                    style={{
                        position: "relative",
                        width: 52,
                        height: 52,
                        borderRadius: "50%",
                        border: `2.5px ${isManual ? "dashed" : "solid"} ${colorScheme.border}`,
                        background: colorScheme.bg,
                        display: "flex",
                        alignItems: "center",
                        justifyContent: "center",
                        fontWeight: 700,
                        fontSize: 15,
                        color: colorScheme.text,
                        boxShadow: "0 2px 6px rgba(0, 0, 0, 0.08)",
                    }}
                >
                    <PullItemImage
                        iconUrl={pull.icon_url}
                        previewUrl={pull.preview_url}
                        name={pull.name}
                        onPreviewOpenChange={onPreviewOpenChange}
                        // Free pulls are muted, the badges around the circle stay readable
                        style={isFree ? { opacity: 0.6 } : undefined}
                    />
                    {isUp && (
                        <span
                            style={{
                                position: "absolute",
                                top: -5,
                                left: -6,
                                background: "#d4380d",
                                color: "#fff",
                                fontSize: 9,
                                fontWeight: 700,
                                lineHeight: "13px",
                                padding: "0 4px",
                                borderRadius: 8,
                                border: "1.5px solid #fff",
                            }}
                        >
                            UP
                        </span>
                    )}
                    {isFree && (
                        <span
                            style={{
                                position: "absolute",
                                top: -5,
                                right: -6,
                                background: "#389e0d",
                                color: "#fff",
                                fontSize: 9,
                                fontWeight: 700,
                                lineHeight: "13px",
                                padding: "0 4px",
                                borderRadius: 8,
                                border: "1.5px solid #fff",
                            }}
                        >
                            FREE
                        </span>
                    )}
                    <span
                        style={{
                            position: "absolute",
                            bottom: -4,
                            right: -4,
                            background: colorScheme.badgeBg,
                            color: "#fff",
                            fontSize: 11,
                            fontWeight: 700,
                            lineHeight: "16px",
                            padding: "1px 5px",
                            borderRadius: 10,
                            border: "1.5px solid #fff",
                        }}
                    >
                        {pull.pity}
                        {hasDeclaredPity && "*"}
                    </span>
                </div>
                <Text
                    ellipsis={{ tooltip: false }}
                    style={{
                        fontSize: 11,
                        marginTop: 6,
                        maxWidth: 66,
                        textAlign: "center",
                        lineHeight: 1.2,
                    }}
                >
                    {pull.name}
                </Text>
            </div>
        </Tooltip>
    );
}

export default function PullsBannerCard({ banner, rarityConfig, onViewDetails }) {
    const sLabel = rarityConfig?.s?.label || "S";
    const sShort = rarityConfig?.s?.short || "S";
    const aLabel = rarityConfig?.a?.label || "A";
    const aShort = rarityConfig?.a?.short || "A";
    // A banner may count its own unit (e.g. NTE "Rolls" vs "Arcs"); fallback to the game-wide label
    const pullLabel = banner?.pull_label || rarityConfig?.pull_label || "Pulls";
    const pullSingularLabel = banner?.pull_singular_label || rarityConfig?.pull_singular_label || "Pull";

    const sPityPercent = Math.min(100, Math.round((banner.s_pity / (banner.s_max_pity || 90)) * 100));
    const aPityPercent = Math.min(100, Math.round((banner.a_pity / (banner.a_max_pity || 10)) * 100));

    const recentPulls = (banner.recent_s_pulls || []).slice(0, 6);

    // Banner the pity counters refer to, for groups whose pity resets per banner (Endfield)
    const pityPoolName = typeof banner.pity_pool_name === "string" && banner.pity_pool_name.trim() !== ""
        ? banner.pity_pool_name
        : null;

    return (
        <Card
            hoverable
            styles={{
                body: {
                    padding: 20,
                    display: "flex",
                    flexDirection: "column",
                    justifyContent: "space-between",
                    height: "100%",
                    gap: 16,
                },
            }}
            style={{ height: "100%" }}
        >
            <div>
                {/* Top Header & Stats Strip */}
                <div
                    style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "flex-start",
                        flexWrap: "wrap",
                        gap: 8,
                        marginBottom: 14,
                    }}
                >
                    <div>
                        <Title level={5} style={{ margin: 0 }}>
                            {banner.name}
                        </Title>
                        <Text type="secondary" style={{ fontSize: 12 }}>
                            {banner.s_count} × {sLabel} · {banner.a_count} × {aLabel}
                        </Text>
                    </div>

                    {/* zzz.rng.moe style stats pill */}
                    <div
                        style={{
                            display: "inline-flex",
                            alignItems: "center",
                            gap: 12,
                            padding: "6px 12px",
                            borderRadius: 8,
                            background: "rgba(0, 0, 0, 0.04)",
                            border: "1px solid rgba(5, 5, 5, 0.08)",
                        }}
                    >
                        <Tooltip title={`Total ${pullLabel}: ${banner.total_pulls.toLocaleString()}`}>
                            <Space size={4}>
                                <RocketOutlined style={{ color: "#1677ff" }} />
                                <Text strong style={{ fontSize: 14 }}>
                                    {banner.total_pulls.toLocaleString()}
                                </Text>
                            </Space>
                        </Tooltip>

                        <Tooltip title={pityPoolName ? `${sLabel} Pity Counter in ${pityPoolName}` : `${sLabel} Pity Counter`}>
                            <span style={{ fontSize: 13, fontWeight: 700, color: "#fa8c16" }}>
                                {sShort} {banner.s_pity}/{banner.s_max_pity}
                            </span>
                        </Tooltip>

                        <Tooltip title={`${aLabel} Pity Counter`}>
                            <span style={{ fontSize: 13, fontWeight: 700, color: "#722ed1" }}>
                                {aShort} {banner.a_pity}/{banner.a_max_pity}
                            </span>
                        </Tooltip>
                    </div>
                </div>

                {/* Dual Pity Progress Bars */}
                <div style={{ marginBottom: 16 }}>
                    <div style={{ display: "flex", justifyContent: "space-between", fontSize: 12, marginBottom: 2 }}>
                        {pityPoolName ? (
                            <Text
                                type="secondary"
                                ellipsis={{ tooltip: `${sLabel} Pity in ${pityPoolName}` }}
                                style={{ minWidth: 0, marginRight: 8 }}
                            >
                                {sLabel} Pity in {pityPoolName}
                            </Text>
                        ) : (
                            <Text type="secondary">{sLabel} Pity</Text>
                        )}
                        <Text strong style={{ color: "#fa8c16", ...(pityPoolName ? { flexShrink: 0 } : {}) }}>
                            {banner.s_pity} / {banner.s_max_pity} ({Math.max(0, banner.s_max_pity - banner.s_pity)} left)
                        </Text>
                    </div>
                    <Progress
                        percent={sPityPercent}
                        strokeColor="#fa8c16"
                        showInfo={false}
                        size="small"
                        style={{ marginBottom: 8 }}
                    />

                    <div style={{ display: "flex", justifyContent: "space-between", fontSize: 12, marginBottom: 2 }}>
                        <Text type="secondary">{aLabel} Pity</Text>
                        <Text strong style={{ color: "#722ed1" }}>
                            {banner.a_pity} / {banner.a_max_pity} ({Math.max(0, banner.a_max_pity - banner.a_pity)} left)
                        </Text>
                    </div>
                    <Progress
                        percent={aPityPercent}
                        strokeColor="#722ed1"
                        showInfo={false}
                        size="small"
                    />
                </div>

                {/* Recent S / 5* / 6* Pulls Row */}
                <div>
                    <Text type="secondary" style={{ fontSize: 12, display: "block", marginBottom: 10 }}>
                        Recent {sLabel} {pullLabel}
                    </Text>

                    {recentPulls.length > 0 ? (
                        <div
                            style={{
                                display: "flex",
                                flexWrap: "wrap",
                                gap: 10,
                                minHeight: 74,
                                alignItems: "flex-start",
                            }}
                        >
                            {recentPulls.map((pull) => (
                                <PullsRecentBadge
                                    key={pull.id}
                                    pull={pull}
                                    maxPity={banner.s_max_pity}
                                    pullSingularLabel={pullSingularLabel}
                                />
                            ))}
                        </div>
                    ) : (
                        <div
                            style={{
                                minHeight: 74,
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "center",
                                background: "rgba(0, 0, 0, 0.02)",
                                borderRadius: 8,
                            }}
                        >
                            <Text type="secondary" style={{ fontSize: 12 }}>
                                No {sLabel} {pullLabel} recorded yet
                            </Text>
                        </div>
                    )}
                </div>
            </div>

            {/* Action Button */}
            <Button
                type="primary"
                block
                icon={<RightOutlined />}
                iconPlacement="end"
                onClick={() => onViewDetails(banner.key)}
                style={{ marginTop: 8, fontWeight: 600 }}
            >
                View Details
            </Button>
        </Card>
    );
}
