import { Card, Button, Space, Typography, Tooltip, Progress } from "antd";
import { RightOutlined, RocketOutlined } from "@ant-design/icons";
import React, { useEffect, useState } from "react";

const { Text, Title } = Typography;

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

/**
 * Generates a short 2-letter monogram or icon label for an item name.
 */
function getInitials(name) {
    if (!name) return "?";
    const words = name.trim().split(/\s+/);
    if (words.length === 1) {
        return words[0].substring(0, 2).toUpperCase();
    }
    return (words[0][0] + words[1][0]).toUpperCase();
}

export function PullsRecentBadge({ pull, maxPity = 90, pullSingularLabel = "Pull" }) {
    const colorScheme = getPityColor(pull.pity, maxPity);
    const [imgError, setImgError] = useState(false);

    useEffect(() => {
        setImgError(false);
    }, [pull.icon_url]);

    const showImage = Boolean(pull.icon_url) && !imgError;

    return (
        <Tooltip
            title={
                <div style={{ fontSize: 12 }}>
                    <div style={{ fontWeight: 600 }}>{pull.name}</div>
                    <div>Pity: {pull.pity} ({pullSingularLabel} #{pull.pull_number})</div>
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
                        border: `2.5px solid ${colorScheme.border}`,
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
                    <div
                        style={{
                            width: "100%",
                            height: "100%",
                            borderRadius: "50%",
                            overflow: "hidden",
                            display: "flex",
                            alignItems: "center",
                            justifyContent: "center",
                        }}
                    >
                        {showImage ? (
                            <img
                                src={pull.icon_url}
                                alt={pull.name}
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
                            getInitials(pull.name)
                        )}
                    </div>
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

                        <Tooltip title={`${sLabel} Pity Counter`}>
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
                        <Text type="secondary">{sLabel} Pity</Text>
                        <Text strong style={{ color: "#fa8c16" }}>
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
