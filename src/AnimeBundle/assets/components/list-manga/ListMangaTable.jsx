import ListMangaDetailModal from "@AnimeBundle/components/list-manga/ListMangaDetailModal";
import StandardTable from "@CoreBundle/components/StandardTable";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { ReloadOutlined } from "@ant-design/icons";
import { App, FloatButton } from "antd";
import React from "react";

export default function ListMangaTable() {
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
        <ListMangaDetailModal open={detailModalOpen} setOpen={setDetailModalOpen} selectedId={selectedId}/>
        <FloatButton.Group shape="circle" style={{ insetInlineEnd: 24 }}>
            <FloatButton icon={<ReloadOutlined/>} tooltip={<div>Refresh list</div>} onClick={() => {
                api
                    .withErrorHandling()
                    .listManga()
                    .refresh()
                    .then(res => {
                        message.open({
                            key: 'list-manga-refresh-loader',
                            type: 'success',
                            content: 'Refreshing list manga...',
                            duration: 2.5,
                        });
                    });
            }}/>
        </FloatButton.Group>
        <StandardTable
            backendFunction={api.withErrorHandling().listManga().table}
            tableStyle={{ overflowY: 'auto', width: '100%' }}
            tableOnRow={onRowClick}
        />
    </>;

}