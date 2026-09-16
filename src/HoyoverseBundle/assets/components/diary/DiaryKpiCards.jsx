import { ArrowUpOutlined, ArrowDownOutlined, RocketOutlined } from "@ant-design/icons";
import { Row, Col, Card, Statistic } from "antd";
import React, { useMemo } from "react";

export default function DiaryKpiCards({ summary, isPrimaryGacha }) {
    const peakDay = useMemo(() => {
        if (!summary?.daily_timeline || summary.daily_timeline.length === 0) return null;
        return summary.daily_timeline.reduce(
            (prev, curr) => (curr.amount > prev.amount ? curr : prev),
            summary.daily_timeline[0]
        );
    }, [summary?.daily_timeline]);

    return (
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
    );
}