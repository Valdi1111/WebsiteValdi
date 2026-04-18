import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import missingCoverUrl from "@BooksBundle/images/books-missing-cover.png";
import { DownloadOutlined, ExportOutlined } from "@ant-design/icons";
import { Col, Descriptions, Modal, Row, Space, Typography } from "antd";
import { Link } from "react-router";
import React from "react";

/**
 * Dropdown menu - about
 * @param id
 * @param open
 * @param setOpen
 * @returns {JSX.Element}
 * @constructor
 */
export default function ItemInfo({ id, open, setOpen }) {
    const [loading, setLoading] = React.useState(true);
    const [data, setData] = React.useState({
        path: '',
        metadata: {},
        coverUrl: null,
    });

    const api = useBackendApi();

    const afterOpenChange = React.useCallback((opened) => {
        if (!opened) {
            setLoading(true);
            setData({
                path: '',
                metadata: {},
                coverUrl: null,
            });
            return;
        }
        setLoading(true);
        api
            .withErrorHandling()
            .books()
            .getMetadata(id)
            .then(res => {
                setData({
                    path: res.data.url,
                    metadata: res.data.book_metadata,
                    coverUrl: res.data.book_cache.cover ? res.data.book_cache.cover_url : null,
                });
                setLoading(false);
            });
    }, [id]);

    return <Modal
        title={<Space>
            <span>About this book</span>
            {data.coverUrl &&
                <Link to={api.books().coverUrl(id)} target="_blank">
                    <ExportOutlined/>
                </Link>
            }
            <Link to={api.books().epubUrl(id)} target="_blank">
                <DownloadOutlined/>
            </Link>
        </Space>}
        footer={null}
        loading={loading}
        open={open}
        afterOpenChange={afterOpenChange}
        onCancel={() => setOpen(false)}
        destroyOnHidden
    >
        <Row gutter={[8, 8]}>
            <Col span={8}>
                <img
                    style={{ width: '100%', maxWidth: '100%', height: 'auto' }}
                    src={data.coverUrl ?? missingCoverUrl}
                    alt="Book cover"
                    loading="lazy"
                />
            </Col>
            <Col span={16}>
                <Typography.Title level={5}>{data.metadata.title}</Typography.Title>
                <Typography.Text type="secondary">{data.metadata.creator}</Typography.Text>
            </Col>
        </Row>
        <Descriptions column={2} layout="vertical" items={[
            {
                key: 1,
                label: 'Path',
                children: data.path,
                span: 2,
            },
            {
                key: 2,
                label: 'Publisher',
                children: data.metadata.publisher,
            },
            {
                key: 5,
                label: 'Language',
                children: data.metadata.language,
            },
            {
                key: 3,
                label: 'Publication Date',
                children: data.metadata.publication,
            },
            {
                key: 4,
                label: 'Modified Date',
                children: data.metadata.modified,
            },
            {
                key: 6,
                label: 'Identifier',
                children: data.metadata.identifier,
                span: 2,
            },
            {
                key: 7,
                label: 'Copyright',
                children: data.metadata.rights,
                span: 2,
            },
        ]}/>
    </Modal>;
}
