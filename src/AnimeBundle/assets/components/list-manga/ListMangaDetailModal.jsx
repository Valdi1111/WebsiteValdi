import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { Descriptions, Modal } from "antd";
import { Link } from "react-router-dom";
import React from "react";

export default function ListMangaDetailModal({ open, setOpen, selectedId }) {
    const [loading, setLoading] = React.useState(true);
    const [data, setData] = React.useState(null);
    const api = useBackendApi();

    const afterOpenChange = React.useCallback(opened => {
        if (!opened) {
            setLoading(true);
            setData(null);
            return;
        }
        setLoading(true);
        api
            .withErrorHandling()
            .listManga()
            .getId(selectedId)
            .then(res => {
                setData(res.data);
                setLoading(false);
            });
    }, [selectedId]);

    const items = React.useMemo(() => {
        if (!data) {
            return [];
        }
        return [
            {
                key: 1,
                label: 'MyAnimeList ID',
                children: <Link to={`https://myanimelist.net/manga/${data.id}`} target="_blank">
                    {data.id}
                </Link>,
                span: 2,
            },
            {
                key: 2,
                label: 'Status',
                children: data.status,
                span: 2,
            },
            {
                key: 3,
                label: 'Title',
                children: data.title,
                span: 4,
            },
            {
                key: 4,
                label: 'Title english',
                children: data.title_en,
                span: 4,
            },
            {
                key: 5,
                label: 'Nsfw',
                children: data.nsfw,
                span: 2,
            },
            {
                key: 6,
                label: 'Type',
                children: data.media_type,
                span: 2,
            },
            {
                key: 7,
                label: 'Volumes',
                children: data.num_volumes,
                span: 2,
                hidden: true,
            },
            {
                key: 8,
                label: 'Chapters',
                children: data.num_chapters,
                span: 2,
                hidden: true,
            },
        ];
    }, [data]);

    return <Modal
        title={<span>Manga details</span>}
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