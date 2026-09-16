import { Row, Col, Card, Space, Typography, Progress, Empty } from "antd";
import { PieChartOutlined, BarChartOutlined } from "@ant-design/icons";
import React, { useState, useMemo } from "react";

const { Text } = Typography;

export default function DiaryVisualBreakdown({
                                                 summary,
                                                 selectedAction,
                                                 selectedDate,
                                                 onToggleActionFilter,
                                                 onToggleDateFilter,
                                             }) {
    const [hoveredAction, setHoveredAction] = useState(null);
    const [hoveredDate, setHoveredDate] = useState(null);

    const maxDayAmount = useMemo(() => {
        if (!summary?.daily_timeline || summary.daily_timeline.length === 0) return 1;
        return Math.max(...summary.daily_timeline.map((d) => d.amount), 1);
    }, [summary?.daily_timeline]);

    return (
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
                                            onClick={() => onToggleActionFilter(item.actionName)}
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
                                const percent = Math.min(100, Math.round((item.amount / maxDayAmount) * 100));
                                const isSelected = selectedDate === item.date;
                                const isHovered = hoveredDate === item.date;

                                return (
                                    <div
                                        key={idx}
                                        onClick={() => onToggleDateFilter(item.date)}
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
    );
}