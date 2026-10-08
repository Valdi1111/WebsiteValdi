import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import ExternalTitleLink from "@AnimeBundle/components/ExternalTitleLink";
import { Descriptions, Modal } from "antd";
import React from "react";

export default function ListAnimeDetailModal({ open, setOpen, selectedId }) {
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
            .listAnime()
            .getId(selectedId)
            .then(res => {
                setData(res.data);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [selectedId, api]);

    const items = React.useMemo(() => {
        if (!data) {
            return [];
        }
        return [
            {
                key: 1,
                label: 'MyAnimeList',
                children: (
                    <ExternalTitleLink
                        id={data.id}
                        url={`https://myanimelist.net/anime/${data.id}`}
                        fetchTitle={() => api.myAnimeList().animeTitle(data.id)}
                    />
                ),
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
                label: 'Episodes',
                children: data.num_episodes,
                span: 4,
                hidden: true,
            },
        ];
    }, [data, api]);

    return (
        <Modal
            title={<span>Anime details</span>}
            footer={null}
            loading={loading}
            open={open}
            afterOpenChange={afterOpenChange}
            onCancel={() => setOpen(false)}
            destroyOnHidden
        >
            <Descriptions column={4} layout="vertical" items={items} />
        </Modal>
    );
}
