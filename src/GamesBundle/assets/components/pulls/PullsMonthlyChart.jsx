import { Card, Space, Typography, Empty, Grid } from "antd";
import { AreaChartOutlined } from "@ant-design/icons";
import Chart from "chart.js/auto";
import React, { useEffect, useRef } from "react";

const { Text } = Typography;
const { useBreakpoint } = Grid;

export default function PullsMonthlyChart({ monthlyTimeline, rarityConfig }) {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;
    const canvasRef = useRef(null);
    const chartInstanceRef = useRef(null);

    const sLabel = rarityConfig?.s?.label || "S";
    const aLabel = rarityConfig?.a?.label || "A";
    const pullLabel = rarityConfig?.pull_label || "Pulls";
    const pullSingularLabel = rarityConfig?.pull_singular_label || "Pull";

    useEffect(() => {
        if (!canvasRef.current || !monthlyTimeline || monthlyTimeline.length === 0) {
            return;
        }

        if (chartInstanceRef.current) {
            chartInstanceRef.current.destroy();
        }

        const labels = monthlyTimeline.map((item) => item.month);
        const sData = monthlyTimeline.map((item) => item.s_count);
        const aData = monthlyTimeline.map((item) => item.a_count);
        const totalData = monthlyTimeline.map((item) => item.total);

        const ctx = canvasRef.current.getContext("2d");
        chartInstanceRef.current = new Chart(ctx, {
            type: "line",
            data: {
                labels,
                datasets: [
                    {
                        label: sLabel,
                        data: sData,
                        borderColor: "#faad14",
                        backgroundColor: "rgba(250, 173, 20, 0.30)",
                        pointBackgroundColor: "#faad14",
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.3,
                        fill: true,
                    },
                    {
                        label: aLabel,
                        data: aData,
                        borderColor: "#b37feb",
                        backgroundColor: "rgba(179, 127, 235, 0.30)",
                        pointBackgroundColor: "#b37feb",
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.3,
                        fill: true,
                    },
                    {
                        label: "Total",
                        data: totalData,
                        borderColor: "#69b1ff",
                        backgroundColor: "rgba(105, 177, 255, 0.28)",
                        pointBackgroundColor: "#69b1ff",
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.3,
                        fill: true,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: "index",
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: "top",
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                        },
                    },
                    tooltip: {
                        backgroundColor: "rgba(20, 20, 30, 0.92)",
                        titleColor: "#fff",
                        bodyColor: "#fff",
                        padding: 10,
                        cornerRadius: 8,
                    },
                },
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 0,
                        },
                        grid: {
                            display: false,
                        },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                        },
                    },
                },
            },
        });

        return () => {
            if (chartInstanceRef.current) {
                chartInstanceRef.current.destroy();
                chartInstanceRef.current = null;
            }
        };
    }, [monthlyTimeline, sLabel, aLabel]);

    return (
        <Card
            styles={{
                header: {
                    height: "auto",
                    padding: isMobile ? "12px 12px" : "16px 20px",
                },
                body: {
                    padding: isMobile ? "12px 10px" : "20px 24px",
                },
            }}
            title={
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                    <Space>
                        <AreaChartOutlined />
                        <span>Monthly {pullLabel} Distribution</span>
                    </Space>
                    <Text type="secondary" style={{ fontSize: 12, fontWeight: "normal" }}>
                        Hover to inspect monthly breakdown
                    </Text>
                </div>
            }
        >
            {monthlyTimeline && monthlyTimeline.length > 0 ? (
                <div style={{ height: isMobile ? 260 : 320, width: "100%", position: "relative" }}>
                    <canvas ref={canvasRef} />
                </div>
            ) : (
                <div style={{ height: 220, display: "flex", alignItems: "center", justifyContent: "center" }}>
                    <Empty description={`No monthly ${pullSingularLabel.toLowerCase()} history available`} />
                </div>
            )}
        </Card>
    );
}
