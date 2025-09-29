import ListMangaDetailModal from "@AnimeBundle/components/list-manga/ListMangaDetailModal";
import StandardTable from "@CoreBundle/components/StandardTable";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import React from "react";

export default function ListMangaTable() {
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
        <ListMangaDetailModal open={detailModalOpen} setOpen={setDetailModalOpen} selectedId={selectedId}/>
        <StandardTable
            backendFunction={api.listManga.get}
            tableStyle={{ overflowY: 'auto', width: '100%' }}
            tableOnRow={onRowClick}
        />
    </>;

}