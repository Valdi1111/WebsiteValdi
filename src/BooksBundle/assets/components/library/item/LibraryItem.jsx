import ItemCover from "@BooksBundle/components/library/item/ItemCover";
import ItemProgress from "@BooksBundle/components/library/item/ItemProgress";
import ItemInfo from "@BooksBundle/components/library/item/ItemInfo";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { useNavigate } from "react-router-dom";
import { App, Card, Dropdown } from "antd";
import React from "react";
import {
    DeleteOutlined,
    EllipsisOutlined,
    ExclamationCircleFilled,
    ExportOutlined,
    InfoCircleOutlined,
    RedoOutlined
} from "@ant-design/icons";

/**
 * @typedef {{id: int, url: string, shelf_id: int, book_cache: {pages: int, cover: string}, book_metadata: {title: string, creator: string}, book_progress: {position: ?string, page: int}}} BookProps
 */

/**
 * @param {{hide_shelf: boolean|undefined, book: BookProps}} props
 * @returns {JSX.Element}
 * @constructor
 */
export default function LibraryItem(props) {
    const [infoOpen, setInfoOpen] = React.useState(false);
    const hide_shelf = props.hide_shelf | false;
    const { id, shelf_id } = props.book;
    const { cover, cover_url, pages } = props.book.book_cache;
    const { title, creator } = props.book.book_metadata;
    const [page, setPage] = React.useState(props.book.book_progress.page);

    const { modal } = App.useApp();
    const navigate = useNavigate();
    const api = useBackendApi();

    function setRead(val) {
        setPage(val ? -1 : 0);
    }

    function onRecreateOpen() {
        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: 'Are you sure you want to recreate the cache for this book?',
            content: title,
            onOk: () => api
                .withLoadingMessage({
                    key: 'book-recreate-loader',
                    loadingContent: 'Recreating book...',
                    successContent: 'Book recreated successfully',
                })
                .books()
                .recreate(id)
                .then(
                data => {
                    console.log("Book", data.id, "Cache recreated successfully!");
                },
                err => console.error(err)
            ),
        });
    }

    function onDeleteOpen() {
        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: 'Are you sure you want to delete this book?',
            content: title,
            onOk: () => api
                .withLoadingMessage({
                    key: 'book-delete-loader',
                    loadingContent: 'Deleting book...',
                    successContent: 'Book deleted successfully',
                })
                .books()
                .delete(id)
                .then(res => {
                    console.log("Book", id, "Deleted successfully!");
                }),
        });
    }

    return <>
        <ItemInfo id={id} open={infoOpen} setOpen={setInfoOpen}/>
        <Card
            styles={{ body: { display: 'none' } }}
            hoverable={true}
            cover={<ItemCover id={id} hasCover={cover} coverUrl={cover_url} title={title} creator={creator}/>}
            style={{ borderRadius: "10px" }}
            actions={[
                <ItemProgress id={id} page={page} total={pages} setRead={setRead}/>,
                <InfoCircleOutlined key="info" onClick={() => setInfoOpen(true)}/>,
                <Dropdown destroyOnHidden menu={{
                    items: [
                        {
                            key: 'goToShelf',
                            label: 'Go to shelf',
                            icon: <ExportOutlined/>,
                            disabled: hide_shelf || !shelf_id,
                            onClick: () => navigate(`/library/shelves/${shelf_id}`),
                        },
                        {
                            key: 'recreate',
                            label: 'Recreate',
                            icon: <RedoOutlined/>,
                            onClick: () => onRecreateOpen(),
                        },
                        {
                            key: 'delete',
                            label: 'Delete',
                            icon: <DeleteOutlined/>,
                            danger: true,
                            onClick: () => onDeleteOpen(),
                        },
                    ]
                }}>
                    <EllipsisOutlined key="ellipsis"/>
                </Dropdown>,
            ]}
        />
    </>;
}
