import React, { useState } from "react";
import { Button, Grid } from "antd";
import {
    CheckCircleOutlined,
    ClockCircleOutlined,
    CloseCircleOutlined,
    PlusOutlined,
    SyncOutlined
} from "@ant-design/icons";
import DownloadAddModal from "@AnimeBundle/components/downloads/DownloadAddModal";
import DownloadDetailModal from "@AnimeBundle/components/downloads/DownloadDetailModal";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import RemoteTable from "@CoreBundle/components/standard-table/RemoteTable.jsx";

const { useBreakpoint } = Grid;

const PROVIDER_CATALOG = {
    animeworld: { text: "AnimeWorld", color: "volcano" },
    animeunity: { text: "AnimeUnity", color: "purple" },
};

const TRACKER_TAG_CATALOG = {
    myanimelist: { text: "MyAnimeList", color: "blue" },
    anilist: { text: "AniList", color: "geekblue" },
};

/**
 * Unified catalog for episode download queue states (tags & filters)
 */
const STATUS_CATALOG = {
    created: { text: "Created", color: "default", icon: <ClockCircleOutlined /> },
    downloading: { text: "Downloading", color: "processing", icon: <SyncOutlined spin /> },
    completed: { text: "Completed", color: "success", icon: <CheckCircleOutlined /> },
    error_starting: { text: "Error Starting", color: "error", icon: <CloseCircleOutlined /> },
    error_downloading: { text: "Error Downloading", color: "error", icon: <CloseCircleOutlined /> },
};

export default function DownloadsTable() {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;

    const [addModalOpen, setAddModalOpen] = useState(false);
    const [detailModalOpen, setDetailModalOpen] = useState(false);
    const [selectedId, setSelectedId] = useState(null);
    const api = useBackendApi();

    const columns = [
        {
            title: "ID",
            dataIndex: "id",
            fixed: "left",
            sorter: true,
            defaultSortOrder: "descend",
            filterType: "number",
            filterOperators: ["eq", "gt", "lt"],
        },
        {
            title: "Provider",
            dataIndex: "provider",
            valueType: "tags",
            filterType: "tags",
            tagCatalog: PROVIDER_CATALOG,
        },
        {
            title: "Tracker",
            dataIndex: "tracker",
            valueType: "tags",
            filterType: "tags",
            tagCatalog: TRACKER_TAG_CATALOG,
        },
        {
            title: "Episode URL",
            dataIndex: "episode_url",
            filterType: "text",
            ellipsis: true,
        },
        {
            title: "Folder",
            dataIndex: "folder",
            filterType: "text",
        },
        {
            title: "Episode",
            dataIndex: "episode",
            filterType: "number",
        },
        {
            title: "State",
            dataIndex: "state",
            valueType: "tags",
            filterType: "tags",
            tagCatalog: STATUS_CATALOG,
            tooltipDataIndex: "last_error",
        },
        {
            title: "Attempts",
            dataIndex: "attempts_count",
            align: "center",
        },
        {
            title: "Started",
            dataIndex: "started",
            valueType: "datetime",
            filterType: "date",
            sorter: true,
        },
        {
            title: "Completed",
            dataIndex: "completed",
            valueType: "datetime",
            filterType: "date",
            sorter: true,
        },
    ];

    return (
        <div
            style={{
                flex: 1,
                padding: isMobile ? "12px 10px" : 24,
                overflowY: "auto",
                boxSizing: "border-box",
            }}
        >
            <DownloadAddModal open={addModalOpen} setOpen={setAddModalOpen} />
            <DownloadDetailModal
                open={detailModalOpen}
                setOpen={setDetailModalOpen}
                selectedId={selectedId}
            />

            <RemoteTable
                title="Episode Downloads"
                subtitle="Monitor automated queue status, download jobs, and completion logs"
                columns={columns}
                fetchData={(params) => api.withErrorHandling().downloads().table(params)}
                extraToolbarActions={
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => setAddModalOpen(true)}>
                        Add Download
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
