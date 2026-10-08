import React, { useState } from "react";
import { App, Button, Grid } from "antd";
import {
    CheckCircleOutlined, ClockCircleOutlined,
    CloseCircleOutlined,
    CloudSyncOutlined,
    ExclamationCircleOutlined,
    SyncOutlined
} from "@ant-design/icons";
import ListAnimeDetailModal from "@AnimeBundle/components/list-anime/ListAnimeDetailModal";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import RemoteTable from "@CoreBundle/components/standard-table/RemoteTable.jsx";

const { useBreakpoint } = Grid;

/**
 * Unified catalog for anime consumption progress statuses (tags & filters)
 */
const STATUS_CATALOG = {
    watching: { text: "Watching", color: "processing", icon: <SyncOutlined spin /> },
    completed: { text: "Completed", color: "success", icon: <CheckCircleOutlined /> },
    on_hold: { text: "On Hold", color: "warning", icon: <ExclamationCircleOutlined /> },
    dropped: { text: "Dropped", color: "error", icon: <CloseCircleOutlined /> },
    plan_to_watch: { text: "Plan To Watch", color: "default", icon: <ClockCircleOutlined /> },
};

/**
 * Catalog for nsfw
 */
const NSFW_CATALOG = {
    white: { text: "White (SFW)", color: "green" },
    gray: { text: "Gray (Questionable)", color: "orange" },
    black: { text: "Black (NSFW)", color: "red" },
};

/**
 * Catalog for anime media types
 */
const MEDIA_TYPE_CATALOG = {
    tv: { text: "TV" },
    ova: { text: "OVA" },
    movie: { text: "Movie" },
    special: { text: "Special" },
    ona: { text: "ONA" },
    music: { text: "Music" },
    cm: { text: "CM" },
    pv: { text: "PV" },
    tv_special: { text: "TV Special" },
    unknown: { text: "Unknown" },
};

export default function ListAnimeTable() {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;

    const [detailModalOpen, setDetailModalOpen] = useState(false);
    const [selectedId, setSelectedId] = useState(null);
    const { message } = App.useApp();
    const api = useBackendApi();

    const columns = [
        {
            title: "ID",
            dataIndex: "id",
            fixed: "left",
            sorter: true,
            filterType: "number",
            filterOperators: ["eq", "gt", "lt"],
        },
        {
            title: "Title",
            dataIndex: "title",
            sorter: true,
            filterType: "text",
        },
        {
            title: "English Title",
            dataIndex: "title_en",
            filterType: "text",
            hidden: true,
        },
        {
            title: "Episodes",
            dataIndex: "num_episodes",
            sorter: true,
            filterType: "number",
        },
        {
            title: "Type",
            dataIndex: "media_type",
            valueType: "tags",
            filterType: "tags",
            tagColor: "cyan",
            tagCatalog: MEDIA_TYPE_CATALOG,
        },
        {
            title: "Status",
            dataIndex: "status",
            valueType: "tags",
            filterType: "tags",
            tagCatalog: STATUS_CATALOG,
        },
        {
            title: "NSFW",
            dataIndex: "nsfw",
            valueType: "tags",
            filterType: "tags",
            tagCatalog: NSFW_CATALOG,
            hidden: true,
        },
    ];

    const handleRefreshCache = () => {
        api.withErrorHandling()
            .listAnime()
            .refresh()
            .then(() => {
                message.open({
                    key: "list-anime-refresh-loader",
                    type: "success",
                    content: "Refreshing anime list cache...",
                    duration: 2.5,
                });
            });
    };

    return (
        <div
            style={{
                flex: 1,
                padding: isMobile ? "12px 10px" : 24,
                overflowY: "auto",
                boxSizing: "border-box",
            }}
        >
            <ListAnimeDetailModal
                open={detailModalOpen}
                setOpen={setDetailModalOpen}
                selectedId={selectedId}
            />

            <RemoteTable
                title="Anime Library"
                subtitle="Browse, filter, and track synced anime episodes and publication states"
                columns={columns}
                fetchData={(params) => api.withErrorHandling().listAnime().table(params)}
                extraToolbarActions={
                    <Button type="primary" icon={<CloudSyncOutlined />} onClick={handleRefreshCache}>
                        Sync
                    </Button>
                }
                onRow={(record) => ({
                    onClick: () => {
                        setSelectedId(record.id);
                        setDetailModalOpen(true);
                    },
                })}
            />
        </div>
    );
}
