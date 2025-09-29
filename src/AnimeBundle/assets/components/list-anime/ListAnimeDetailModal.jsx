import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { Descriptions, Modal } from "antd";
import { Link } from "react-router-dom";
import React from "react";

export default function ListAnimeDetailModal({ open, setOpen, selectedId }) {
    const [loading, setLoading] = React.useState(true);
    const [listData, setListData] = React.useState({});
    const api = useBackendApi();

    const afterOpenChange = React.useCallback(opened => {
        if (!opened) {
            setLoading(true);
            setListData({});
            return;
        }
        setLoading(true);
        api.listAnime.getId(selectedId).then(
            res => {
                setListData(res.data);
                setLoading(false);
            },
            err => console.error(err)
        );
    }, [selectedId]);

    const items = React.useMemo(() => [
        {
            key: 1,
            label: 'MyAnimeList ID',
            children: <Link to={`https://myanimelist.net/anime/${listData.id}`} target="_blank">
                {listData.id}
            </Link>,
            span: 2,
        },
        {
            key: 2,
            label: 'Status',
            children: listData.status,
            span: 2,
        },
        {
            key: 3,
            label: 'Title',
            children: listData.title,
            span: 4,
        },
        {
            key: 4,
            label: 'Title english',
            children: listData.title_en,
            span: 4,
        },
        {
            key: 5,
            label: 'Nsfw',
            children: listData.nsfw,
            span: 2,
        },
        {
            key: 6,
            label: 'Type',
            children: listData.media_type,
            span: 2,
        },
        {
            key: 7,
            label: 'Episodes',
            children: listData.num_episodes,
            span: 4,
            hidden: true,
        },
    ], [listData]);

    return <Modal
        title={<span>Anime details</span>}
        footer={null}
        loading={loading}
        open={open}
        afterOpenChange={afterOpenChange}
        onCancel={() => setOpen(false)}
        destroyOnHidden
    >
        <Descriptions column={4} layout={'vertical'} items={items}/>
    </Modal>;

}