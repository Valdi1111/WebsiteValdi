import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import missingCoverUrl from "@BooksBundle/images/books-missing-cover.png";
import { DownloadOutlined, ExportOutlined } from "@ant-design/icons";
import { Col, Descriptions, Modal, Row, Space } from "antd";
import { Link } from "react-router-dom";
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

    const [path, setPath] = React.useState('');
    const [metadata, setMetadata] = React.useState({});
    const [coverUrl, setCoverUrl] = React.useState(null);

    const api = useBackendApi();

    function afterOpenChange(opened){
        if (!opened) {
            setLoading(true);
            setPath('');
            setMetadata({});
            setCoverUrl(null);
            return;
        }
        setLoading(true);
        api.books.getMetadata(id).then(
            res => {
                setPath(res.data.url);
                setMetadata(res.data.book_metadata);
                if (res.data.book_cache.cover) {
                    setCoverUrl(res.data.book_cache.cover_url);
                }
                setLoading(false);
            },
            err => console.error(err)
        );
    }

    return <Modal
        title={<Space>
            <span>About this book</span>
            {coverUrl &&
                <Link to={api.books.coverUrl(id)} target="_blank" className="me-2">
                    <ExportOutlined/>
                </Link>
            }
            <Link to={api.books.epubUrl(id)} target="_blank" className="me-2">
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
                <img className="img-fluid w-100 h-auto" src={coverUrl ?? missingCoverUrl}
                     alt="Book cover" loading="lazy"/>
            </Col>
            <Col span={16}>
                <h6 className="mb-1">{metadata.title}</h6>
                <div className="small">{metadata.creator}</div>
            </Col>
        </Row>
        <Descriptions column={2} layout={'vertical'} items={[
            {
                key: 1,
                label: 'Path',
                children: path,
                span: 2,
            },
            {
                key: 2,
                label: 'Publisher',
                children: metadata.publisher,
            },
            {
                key: 5,
                label: 'Language',
                children: metadata.language,
            },
            {
                key: 3,
                label: 'Publication Date',
                children: metadata.publication,
            },
            {
                key: 4,
                label: 'Modified Date',
                children: metadata.modified,
            },
            {
                key: 6,
                label: 'Identifier',
                children: metadata.identifier,
                span: 2,
            },
            {
                key: 7,
                label: 'Copyright',
                children: metadata.rights,
                span: 2,
            },
        ]}/>
    </Modal>;
}
