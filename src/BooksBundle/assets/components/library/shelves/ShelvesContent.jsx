import ShelfEditModal from "@BooksBundle/components/library/shelves/ShelfEditModal";
import LibraryItem from "@BooksBundle/components/library/item/LibraryItem";
import SpinComponent from "@CoreBundle/components/SpinComponent";
import { useShelves } from "@BooksBundle/components/library/shelves/ShelvesContext";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { App, Badge, Button, Col, Empty, Row, Space, Tabs, Tooltip } from "antd";
import { useNavigate, useParams } from "react-router";
import React from "react";
import {
    DeleteOutlined,
    EditOutlined,
    ExclamationCircleFilled,
    MenuFoldOutlined,
    MenuUnfoldOutlined
} from "@ant-design/icons";

export default function ShelvesContent() {
    const [editOpen, setEditOpen] = React.useState(false);
    const { refreshShelves, refreshContent, contentLoading, content, selectedShelf, collapsed, setCollapsed } = useShelves();
    const { modal } = App.useApp()

    /** @type {MutableRefObject<EventSource>}*/
    const ws = React.useRef(null);
    const navigate = useNavigate();
    const api = useBackendApi();
    const { shelfId } = useParams();

    React.useEffect(() => {
        if (!selectedShelf) {
            return;
        }
        // Append the topic(s) to subscribe as query parameter
        const hub = new URL(MERCURE_HUB_URL, window.origin);
        hub.searchParams.append('topic', `https://books.valdi.ovh/library/shelves/${selectedShelf.id}`);
        // Subscribe to updates
        ws.current = new EventSource(hub, { withCredentials: true });
        ws.current.addEventListener('message', handleWebsocket);
        // Close connection on unmount
        return () => ws.current?.close();
    }, [selectedShelf?.id]);

    /**
     * Will be called every time an update is published by the server
     * @param event
     */
    const handleWebsocket = React.useCallback((event) => {
        const json = JSON.parse(event.data);
        if (json.action === 'book:add') {
            refreshContent();
        }
        if (json.action === 'book:recreate') {
            refreshContent();
        }
        if (json.action === 'book:remove') {
            refreshContent();
        }
    }, []);

    const onDelete = React.useCallback(() => {
        if (!selectedShelf) {
            return;
        }
        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: 'Are you sure you want to delete this shelf?',
            content: selectedShelf.name,
            onOk: () => api
                .withLoadingMessage({
                    key: 'shelf-delete-loader',
                    loadingContent: 'Deleting shelf...',
                    successContent: 'Shelf deleted successfully',
                })
                .shelves()
                .delete(selectedShelf.id)
                .then(res => {
                    navigate('/library/shelves');
                    refreshShelves();
                }),
        });
    }, [selectedShelf?.id]);

    const items = React.useMemo(() => content.map(item => {
        let children = <Empty/>;
        if (item.books.length > 0) {
            children = <Row
                style={{
                    paddingLeft: 8,
                    paddingRight: 8,
                    paddingBottom: 16,
                    marginRight: 0,
                    marginLeft: 0
                }}
                gutter={[8, 8]}>
                {item.books.map(book =>
                    <Col key={book.id} style={{ width: 165 }}>
                        <LibraryItem key={book.id} book={book} hide_shelf/>
                    </Col>
                )}
            </Row>;
        }
        return {
            key: item.folder,
            label: <Space><span>{item.name}</span><Badge count={item.books.length}/></Space>,
            children: children,
        };
    }), [content]);

    if (!shelfId) {
        return <></>;
    }

    return <SpinComponent loading={contentLoading} size="large">
        <title>{selectedShelf?.name ?? "Shelves"}</title>
        <ShelfEditModal visible={editOpen} setVisible={setEditOpen}/>
        <Tabs
            tabBarExtraContent={{
                left: <Space style={{ marginLeft: 16, marginRight: 16 }}>
                    <Button
                        type="text"
                        icon={collapsed ? <MenuUnfoldOutlined/> : <MenuFoldOutlined/>}
                        onClick={() => setCollapsed(!collapsed)}
                    />
                </Space>,
                right: <Space style={{ marginLeft: 16, marginRight: 16 }}>
                    <Tooltip title="Edit shelf">
                        <Button color="primary" variant="filled" icon={<EditOutlined/>}
                                onClick={() => setEditOpen(true)}
                        />
                    </Tooltip>
                    <Tooltip title="Delete shelf">
                        <Button color="danger" variant="filled" icon={<DeleteOutlined/>}
                                onClick={() => onDelete()}
                        />
                    </Tooltip>
                </Space>
            }}
            items={items}
        />
    </SpinComponent>;
}
