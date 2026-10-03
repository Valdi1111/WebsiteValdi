import React, { useState } from "react";
import { Button, Grid } from "antd";
import { PlusOutlined } from "@ant-design/icons";
import StandardTable from "@CoreBundle/components/standard-table/StandardTable";
import SeasonFolderAddModal from "@AnimeBundle/components/season-folders/SeasonFolderAddModal";
import SeasonFolderDetailModal from "@AnimeBundle/components/season-folders/SeasonFolderDetailModal";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";

const { useBreakpoint } = Grid;

export default function SeasonFoldersTable() {
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
            filterType: "number",
            filterOperators: ["eq", "gt", "lt"],
        },
        {
            title: "Title",
            dataIndex: "title",
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
            <SeasonFolderAddModal open={addModalOpen} setOpen={setAddModalOpen} />
            <SeasonFolderDetailModal
                open={detailModalOpen}
                setOpen={setDetailModalOpen}
                selectedId={selectedId}
            />

            <StandardTable
                title="Season Folders"
                subtitle="Local filesystem directory mappings for automated series organizing"
                columns={columns}
                fetchData={(params) => api.withErrorHandling().seasonFolders().table(params)}
                extraToolbarActions={
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => setAddModalOpen(true)}>
                        Add Folder
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