import React, { useState } from "react";
import { App, Button, Grid } from "antd";
import { CloudSyncOutlined } from "@ant-design/icons";
import StandardTable from "@CoreBundle/components/StandardTable";
import ListMangaDetailModal from "@AnimeBundle/components/list-manga/ListMangaDetailModal";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";

const { useBreakpoint } = Grid;

/**
 * Filter options mapped from ListMangaStatus enum
 */
const MANGA_STATUS_FILTERS = [
    { text: "Reading", value: "reading" },
    { text: "Completed", value: "completed" },
    { text: "On Hold", value: "on_hold" },
    { text: "Dropped", value: "dropped" },
    { text: "Plan To Read", value: "plan_to_read" },
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
 * Filter options mapped from ListMangaType enum
 */
const MEDIA_TYPE_FILTERS = [
    { text: "Manga", value: "manga" },
    { text: "One-shot", value: "one_shot" },
    { text: "Doujinshi", value: "doujinshi" },
    { text: "Light Novel", value: "light_novel" },
    { text: "Novel", value: "novel" },
    { text: "Manhwa", value: "manhwa" },
    { text: "Manhua", value: "manhua" },
    { text: "OEL", value: "oel" },
    { text: "Unknown", value: "unknown" },
];

export default function ListMangaTable() {
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
            title: "Volumes",
            dataIndex: "num_volumes",
            sorter: true,
            filterType: "number",
        },
        {
            title: "Chapters",
            dataIndex: "num_chapters",
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
            filters: MANGA_STATUS_FILTERS,
        },
        {
            title: "NSFW",
            dataIndex: "nsfw",
            valueType: "tags",
            tagColorMap: {
                white: "green",
                gray: "black",
                black: "red",
            },
            filters: NSFW_FILTERS,
            hidden: true,
        },
    ];

    const handleRefreshCache = () => {
        api.withErrorHandling()
            .listManga()
            .refresh()
            .then(() => {
                message.open({
                    key: "list-manga-refresh-loader",
                    type: "success",
                    content: "Refreshing manga list...",
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
            <ListMangaDetailModal
                open={detailModalOpen}
                setOpen={setDetailModalOpen}
                selectedId={selectedId}
            />

            <StandardTable
                title="Manga Library"
                subtitle="Browse, filter, and track synced manga chapters and publication states"
                columns={columns}
                fetchData={(params) => api.withErrorHandling().listManga().table(params)}
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