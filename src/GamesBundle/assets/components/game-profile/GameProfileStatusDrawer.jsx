import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { ReloadOutlined } from "@ant-design/icons";
import React, { useState, useCallback, useEffect, useRef } from "react";
import {
    Drawer,
    Button,
    Space,
    Avatar,
    Tag,
    Alert,
    Spin,
    Collapse,
    Descriptions,
    Empty,
    Typography,
} from "antd";

const { Text } = Typography;

/**
 * Age of the status data, from two server timestamps so the client clock never matters.
 * Null when the backend does not know when the data was read.
 */
function formatDataAge(generatedAt, fetchedAt) {
    if (!generatedAt || !fetchedAt) return null;

    const diff = Date.parse(generatedAt) - Date.parse(fetchedAt);
    if (Number.isNaN(diff)) return null;

    // A fresh read can be stamped after the response time it belongs to: treat it as just read
    const minutes = Math.floor(Math.max(0, diff) / 60000);
    if (minutes < 1) return "Data just read";
    if (minutes < 60) return `Data from ${minutes} min ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 48) {
        const rest = minutes % 60;
        return `Data from ${hours} h${rest ? ` ${rest} min` : ""} ago`;
    }

    return `Data from ${Math.floor(hours / 24)} days ago`;
}

// A failed section carries only its generic summary, so there is nothing to unfold
const isExpandable = (section) =>
    section.available && (Boolean(section.description) || (section.fields?.length ?? 0) > 0);

function SectionSummary({ section }) {
    // Block wrapper: a long summary wraps beside the label and stays aligned to the end
    return (
        <div style={{ textAlign: "end" }}>
            {section.available ? (
                <Text>{section.attention ? "🔔 " : ""}{section.value}</Text>
            ) : (
                <Text type="warning">⚠️ {section.value}</Text>
            )}
        </div>
    );
}

function SectionDetails({ section }) {
    const fields = section.fields ?? [];

    return (
        <>
            {section.description ? (
                <div style={{ whiteSpace: "pre-line", marginBottom: fields.length > 0 ? 12 : 0 }}>
                    {section.description}
                </div>
            ) : null}
            {fields.length > 0 ? (
                <Descriptions
                    size="small"
                    column={1}
                    bordered
                    // Index keys: field names are display text and may repeat within a section
                    items={fields.map((field, index) => ({
                        key: index,
                        label: [field.icon, field.name].filter(Boolean).join(" "),
                        children: <span style={{ whiteSpace: "pre-line" }}>{field.value}</span>,
                    }))}
                />
            ) : null}
        </>
    );
}

/**
 * Live status of a game profile (stamina, dailies, check-in...). Read only when the drawer opens and
 * when the user asks for a refresh, which skips the backend caches: reads can be slow and rate limited,
 * so nothing is loaded with the page and nothing refreshes by itself.
 */
export default function GameProfileStatusDrawer({ open, account, profileId, onClose }) {
    const [loading, setLoading] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [status, setStatus] = useState(null);
    const [activeKeys, setActiveKeys] = useState([]);
    const [imgError, setImgError] = useState(false);
    // Id of the latest request: a response is applied only if nothing invalidated it in the meantime
    const requestIdRef = useRef(0);

    const api = useBackendApi();
    // The card already has the header data, no request needed
    const profile = account?.game_profiles?.find((p) => p.id === profileId) ?? null;

    // afterOpenChange(false) fires only after the close animation (and maybe not at all on a quick
    // reopen), so a close or a profile switch invalidates in-flight requests right away
    useEffect(() => {
        requestIdRef.current += 1;
    }, [open, profileId]);

    useEffect(() => {
        setImgError(false);
    }, [profile?.icon_url]);

    const load = useCallback((fresh) => {
        if (!profileId) return;

        const requestId = ++requestIdRef.current;
        if (fresh) {
            setRefreshing(true);
        } else {
            setLoading(true);
        }

        api
            .withErrorHandling()
            .gameProfiles()
            .getStatus(profileId, { fresh })
            .then((res) => {
                if (requestId !== requestIdRef.current) return;
                setStatus(res.data);
            })
            .catch(() => {
                // Error already displayed by withErrorHandling; the previous data stays on screen
            })
            .finally(() => {
                // A newer request (or the close reset) owns the flags now
                if (requestId !== requestIdRef.current) return;
                setLoading(false);
                setRefreshing(false);
            });
    }, [api, profileId]);

    const handleAfterOpenChange = useCallback((opened) => {
        if (!opened) {
            setLoading(false);
            setRefreshing(false);
            setStatus(null);
            setActiveKeys([]);
            return;
        }

        // A quick reopen skips the close callback: never show the previous profile's sections
        setRefreshing(false);
        setStatus(null);
        setActiveKeys([]);
        load(false);
    }, [load]);

    const sections = status?.sections ?? [];
    const dataAge = status ? formatDataAge(status.generated_at, status.fetched_at) : null;
    // A section that failed on a refresh must close, or it would stay open, empty and unclosable
    const expandableKeys = sections.filter(isExpandable).map((section) => section.key);

    const collapseItems = sections.map((section) => {
        const item = {
            key: section.key,
            label: (
                <span style={{ whiteSpace: "nowrap" }}>
                    {[section.icon, section.label].filter(Boolean).join(" ")}
                </span>
            ),
            extra: <SectionSummary section={section} />,
        };

        if (isExpandable(section)) {
            return { ...item, children: <SectionDetails section={section} /> };
        }

        return {
            ...item,
            collapsible: "disabled",
            showArrow: false,
            // Disabled greys the header out: right for a failed section, misleading for one that is
            // fine but simply has nothing more to show
            styles: section.available ? { header: { color: "inherit", cursor: "default" } } : undefined,
        };
    });

    return (
        <Drawer
            title="Profile Status"
            size={520}
            open={open}
            afterOpenChange={handleAfterOpenChange}
            onClose={onClose}
            closable={{ placement: 'end' }}
            destroyOnHidden
            extra={
                <Space>
                    {/* While refreshing the current content stays visible: only the button spins */}
                    <Button
                        icon={<ReloadOutlined />}
                        loading={refreshing}
                        disabled={loading || refreshing}
                        onClick={() => load(true)}
                    >
                        Refresh
                    </Button>
                </Space>
            }
        >
            {/* Header info & avatar */}
            {profile ? (
                <div style={{ display: "flex", alignItems: "center", gap: 16, marginBottom: 16 }}>
                    <Avatar
                        size={64}
                        src={
                            profile.icon_url && !imgError ? (
                                <img
                                    src={profile.icon_url}
                                    alt={profile.game_name}
                                    referrerPolicy="no-referrer"
                                    onError={() => setImgError(true)}
                                />
                            ) : undefined
                        }
                        shape="square"
                        style={{ filter: profile.active ? "none" : "grayscale(100%)" }}
                    >
                        {profile.game_name?.[0] ?? "G"}
                    </Avatar>
                    <div>
                        <h3 style={{ margin: 0 }}>
                            {profile.nickname || `UID ${profile.game_uid}`} {profile.level ? <Tag color="blue">Lv. {profile.level}</Tag> : null}
                        </h3>
                        <div style={{ color: "#888" }}>{profile.game_name || status?.game?.name}</div>
                        <div style={{ fontSize: 12 }}>UID: <code>{profile.game_uid}</code></div>
                    </div>
                </div>
            ) : null}

            {loading ? (
                <div style={{ textAlign: "center", padding: 50 }}>
                    <Spin size="large" />
                </div>
            ) : status ? (
                <>
                    {status.account && !status.account.ok ? (
                        <Alert
                            type="warning"
                            showIcon
                            style={{ marginBottom: 16 }}
                            title={status.account.problem || "There is a problem with the account of this profile."}
                        />
                    ) : null}

                    {status.error ? (
                        <Alert
                            type="error"
                            showIcon
                            style={{ marginBottom: 16 }}
                            title={status.error}
                        />
                    ) : null}

                    {dataAge ? (
                        <div style={{ marginBottom: 12 }}>
                            <Text type="secondary" style={{ fontSize: 12 }}>{dataAge}</Text>
                        </div>
                    ) : null}

                    {sections.length > 0 ? (
                        <Collapse
                            items={collapseItems}
                            activeKey={activeKeys.filter((key) => expandableKeys.includes(key))}
                            onChange={setActiveKeys}
                        />
                    ) : !status.error ? (
                        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing to show for this game." />
                    ) : null}
                </>
            ) : null}
        </Drawer>
    );
}
