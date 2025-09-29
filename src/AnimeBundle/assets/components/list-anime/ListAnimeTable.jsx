import ListAnimeDetailModal from "@AnimeBundle/components/list-anime/ListAnimeDetailModal";
import StandardTable from "@CoreBundle/components/StandardTable";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import React from "react";

export default function ListAnimeTable() {
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
        <ListAnimeDetailModal open={detailModalOpen} setOpen={setDetailModalOpen} selectedId={selectedId}/>
        <StandardTable
            backendFunction={api.listAnime.get}
            tableStyle={{ overflowY: 'auto', width: '100%' }}
            tableOnRow={onRowClick}
        />
    </>;

}