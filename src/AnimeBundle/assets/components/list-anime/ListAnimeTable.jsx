import ListAnimeDetailModal from "@AnimeBundle/components/list-anime/ListAnimeDetailModal";
import StandardTable from "@CoreBundle/components/StandardTable";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { ReloadOutlined } from "@ant-design/icons";
import { App, FloatButton } from "antd";
import React from "react";

export default function ListAnimeTable() {
    const [detailModalOpen, setDetailModalOpen] = React.useState(false);
    const [selectedId, setSelectedId] = React.useState(null);
    const { message } = App.useApp();

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
        <FloatButton.Group shape="circle" style={{ insetInlineEnd: 24 }}>
            <FloatButton icon={<ReloadOutlined/>} tooltip={<div>Refresh list</div>} onClick={() => {
                api
                    .withErrorHandling()
                    .listAnime()
                    .refresh()
                    .then(res => {
                        message.open({
                            key: 'list-anime-refresh-loader',
                            type: 'success',
                            content: 'Refreshing list anime...',
                            duration: 2.5,
                        });
                    });
            }}/>
        </FloatButton.Group>
        <StandardTable
            backendFunction={api.withErrorHandling().listAnime().table}
            tableStyle={{ overflowY: 'auto', width: '100%' }}
            tableOnRow={onRowClick}
        />
    </>;

}