import React, { useState } from "react";
import { App, Button, Grid, Tag } from "antd";
import { CloudSyncOutlined } from "@ant-design/icons";
import StandardTable from "@CoreBundle/components/StandardTable";
import ListAnimeDetailModal from "@AnimeBundle/components/list-anime/ListAnimeDetailModal";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";

const { useBreakpoint } = Grid;

/**
 * Filter options mapped from ListAnimeStatus enum
 */
const ANIME_STATUS_FILTERS = [
    { text: "Watching", value: "watching" },
    { text: "Completed", value: "completed" },
    { text: "On Hold", value: "on_hold" },
    { text: "Dropped", value: "dropped" },
    { text: "Plan To Watch", value: "plan_to_watch" },
];

/**
 * Filter options mapped from Nsfw enum
 */
const NSFW_FILTERS = [
    { text: "White (SFW)", value: "white" },
    { text: "Gray (Questionable)", value: "gray" },
    { text: "Black (NSFW)", value: "black" },
];

/**
 * Filter options mapped from ListAnimeType enum
 */
const MEDIA_TYPE_FILTERS = [
    { text: "TV", value: "tv" },
    { text: "OVA", value: "ova" },
    { text: "Movie", value: "movie" },
    { text: "Special", value: "special" },
    { text: "ONA", value: "ona" },
    { text: "Music", value: "music" },
    { text: "CM", value: "cm" },
    { text: "PV", value: "pv" },
    { text: "TV Special", value: "tv_special" },
    { text: "Unknown", value: "unknown" },
];

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
            tagColor: "cyan",
            filters: MEDIA_TYPE_FILTERS,
        },
        {
            title: "Status",
            dataIndex: "status",
            filters: ANIME_STATUS_FILTERS,
        },
        {
            title: "NSFW",
            dataIndex: "nsfw",
            valueType: "tags",
            tagColorMap: {
                white: "green",
                gray: "orange",
                black: "red",
            },
            filters: NSFW_FILTERS,
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

            <StandardTable
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