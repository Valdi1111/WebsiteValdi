import React, { useState } from "react";
import { Button, Grid } from "antd";
import { PlusOutlined } from "@ant-design/icons";
import SeasonFolderAddModal from "@AnimeBundle/components/season-folders/SeasonFolderAddModal";
import SeasonFolderDetailModal from "@AnimeBundle/components/season-folders/SeasonFolderDetailModal";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { useTracker } from "@AnimeBundle/components/TrackerContext";
import RemoteTable from "@CoreBundle/components/standard-table/RemoteTable.jsx";
import TrackerSelector from "@AnimeBundle/components/TrackerSelector.jsx";

const { useBreakpoint } = Grid;

export default function SeasonFoldersTable() {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;

    const { tracker, setTracker } = useTracker();
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
            title: "Folder",
            dataIndex: "folder",
            sorter: true,
            filterType: "text",
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
            <SeasonFolderAddModal
                open={addModalOpen}
                setOpen={setAddModalOpen}
                tracker={tracker}
            />
            <SeasonFolderDetailModal
                open={detailModalOpen}
                setOpen={setDetailModalOpen}
                selectedId={selectedId}
                tracker={tracker}
            />

            <RemoteTable
                key={tracker}
                title="Season Folders"
                subtitle="Local filesystem directory mappings for automated series organizing"
                columns={columns}
                fetchData={(params) => api.withErrorHandling().seasonFolders().table(tracker, params)}
                extraToolbarActions={
                    <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                        <TrackerSelector />
                        <Button type="primary" icon={<PlusOutlined />} onClick={() => setAddModalOpen(true)}>
                            Add Folder
                        </Button>
                    </div>
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
