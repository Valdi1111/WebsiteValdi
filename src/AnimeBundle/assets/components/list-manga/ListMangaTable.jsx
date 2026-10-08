import React, { useState } from "react";
import { App, Button, Grid } from "antd";
import {
    CheckCircleOutlined, ClockCircleOutlined,
    CloseCircleOutlined,
    CloudSyncOutlined,
    ExclamationCircleOutlined,
    SyncOutlined
} from "@ant-design/icons";
import ListMangaDetailModal from "@AnimeBundle/components/list-manga/ListMangaDetailModal";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import RemoteTable from "@CoreBundle/components/standard-table/RemoteTable.jsx";

const { useBreakpoint } = Grid;

/**
 * Unified catalog for manga reading progress statuses (tags & filters)
 */
const STATUS_CATALOG = {
    reading: { text: "Reading", color: "processing", icon: <SyncOutlined spin /> },
    completed: { text: "Completed", color: "success", icon: <CheckCircleOutlined /> },
    on_hold: { text: "On Hold", color: "warning", icon: <ExclamationCircleOutlined /> },
    dropped: { text: "Dropped", color: "error", icon: <CloseCircleOutlined /> },
    plan_to_read: { text: "Plan To Read", color: "default", icon: <ClockCircleOutlined /> },
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
 * Catalog for manga media types
 */
const MEDIA_TYPE_CATALOG = {
    manga: { text: "Manga" },
    one_shot: { text: "One-shot" },
    doujinshi: { text: "Doujinshi" },
    light_novel: { text: "Light Novel" },
    novel: { text: "Novel" },
    manhwa: { text: "Manhwa" },
    manhua: { text: "Manhua" },
    oel: { text: "OEL" },
    unknown: { text: "Unknown" },
};

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

            <RemoteTable
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
