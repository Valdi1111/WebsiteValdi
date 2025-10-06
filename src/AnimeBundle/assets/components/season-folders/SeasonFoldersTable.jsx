import SeasonFolderDetailModal from "@AnimeBundle/components/season-folders/SeasonFolderDetailModal";
import SeasonFolderAddModal from "@AnimeBundle/components/season-folders/SeasonFolderAddModal";
import StandardTable from "@CoreBundle/components/StandardTable";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { PlusOutlined } from "@ant-design/icons";
import { FloatButton } from "antd";
import React from "react";

export default function SeasonFoldersTable() {
    const [addModalOpen, setAddModalOpen] = React.useState(false);
    const [detailModalOpen, setDetailModalOpen] = React.useState(false);
    const [selectedId, setSelectedId] = React.useState(null);
    const api = useBackendApi();

    function onRowClick(record, rowIndex) {
        return {
            // click row
            onClick: e => {
                setSelectedId(record.id);
                setDetailModalOpen(true);
            },
            // double click row
            onDoubleClick: e => {
            },
            // right button click row
            onContextMenu: e => {
            },
            // mouse enter row
            onMouseEnter: e => {
            },
            // mouse leave row
            onMouseLeave: e => {
            },
        };
    }

    return <>
        <SeasonFolderAddModal open={addModalOpen} setOpen={setAddModalOpen}/>
        <SeasonFolderDetailModal open={detailModalOpen} setOpen={setDetailModalOpen} selectedId={selectedId}/>
        <FloatButton.Group shape="circle" style={{ insetInlineEnd: 24 }}>
            <FloatButton icon={<PlusOutlined/>} tooltip={<div>Add download</div>} onClick={() => {
                setAddModalOpen(true);
            }}/>
        </FloatButton.Group>
        <StandardTable
            backendFunction={api.withErrorHandling().seasonsFolder().table}
            tableStyle={{ overflowY: 'auto', width: '100%' }}
            tableOnRow={onRowClick}
        />
    </>;

}