import { CheckCircleOutlined, CloseCircleOutlined } from "@ant-design/icons";
import { Tag, Tooltip } from "antd";
import React from "react";

function formatDate(value) {
    if (!value) {
        return null;
    }
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date.toLocaleString();
}

/**
 * Session badge for providers whose credentials are renewed automatically (HoYoverse cookie, Gryphline SKPort session).
 *
 * Driven by the renewal failure flag, not by an expiry date: a short-lived session that has expired is simply
 * renewed on its next use, so only a failed renewal needs the user's attention.
 */
export default function SessionStatusTag({ refreshFailed, lastRefreshedAt }) {
    const lastRenewal = formatDate(lastRefreshedAt);

    if (refreshFailed) {
        return (
            <Tooltip title={`The automatic session renewal failed: click Re-login to sign in again.${lastRenewal ? ` Last successful renewal: ${lastRenewal}.` : ""}`}>
                <Tag color="error" icon={<CloseCircleOutlined />} style={{ margin: 0 }}>
                    Session Refresh Failed
                </Tag>
            </Tooltip>
        );
    }

    return (
        <Tooltip title={lastRenewal ? `Last renewal: ${lastRenewal}` : "Not renewed yet"}>
            <Tag color="green" icon={<CheckCircleOutlined />} style={{ margin: 0 }}>
                Session Active
            </Tag>
        </Tooltip>
    );
}
