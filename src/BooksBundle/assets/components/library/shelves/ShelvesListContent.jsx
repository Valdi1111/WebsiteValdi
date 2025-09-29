import LibraryItem from "@BooksBundle/components/library/item/LibraryItem";
import SpinComponent from "@CoreBundle/components/SpinComponent";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { App, Badge, Button, Col, Empty, Form, Input, Modal, Row, Space, Tabs, Tooltip } from "antd";
import { useNavigate, useParams } from "react-router-dom";
import { Helmet } from "react-helmet";
import React from "react";
import {
    DeleteOutlined,
    EditOutlined,
    ExclamationCircleFilled,
    MenuFoldOutlined,
    MenuUnfoldOutlined
} from "@ant-design/icons";

export default function ShelvesListContent({ collapsed, setCollapsed, shelves, setShelves, refreshShelves }) {
    const [loading, setLoading] = React.useState(true);
    const [content, setContent] = React.useState([]);

    const [editOpen, setEditOpen] = React.useState(false);
    const [editConfirmLoading, setEditConfirmLoading] = React.useState(false);
    const [editForm] = Form.useForm();

    /** @type {MutableRefObject<EventSource>}*/
    const ws = React.useRef(null);
    const { modal, message } = App.useApp()
    const navigate = useNavigate();
    const { shelfId } = useParams();
    const api = useBackendApi();

    React.useEffect(() => {
        refreshContent().then(() => startWebsocket())
        return stopWebsocket;
    }, [shelfId]);

    function refreshContent() {
        setLoading(true);
        return api.shelves.getBooks(shelfId).then(
            res => {
                const shelf = getCurrentShelf();
                // Update content
                const data = [{ folder: shelf.path, books: [] }];
                res.data.forEach(b => {
                    const path = b.url.replace(`/${shelf.path}/`, '').split('/', 2);
                    const folder = path.length === 1 ? shelf.path : path[0];
                    let folderEntry = data.find(entry => entry.folder === folder);
                    if (!folderEntry) {
                        folderEntry = { folder: folder, books: [] };
                        data.push(folderEntry);
                    }
                    folderEntry.books.push(b);
                });
                setContent(data);
                // Update shelf book count
                const allShelves = shelves;
                let i = allShelves.findIndex(s => s.id == shelfId);
                allShelves[i].books_count = res.data.length;
                setShelves(allShelves);
                setLoading(false);
            },
            err => console.error(err)
        );
    }

    function getCurrentShelf() {
        return shelves.find(s => s.id == shelfId);
    }

    function startWebsocket() {
        // Append the topic(s) to subscribe as query parameter
        const hub = new URL(MERCURE_HUB_URL, window.origin);
        hub.searchParams.append('topic', `https://books.valdi.ovh/library/shelves/${shelfId}`);
        // Subscribe to updates
        ws.current = new EventSource(hub, { withCredentials: true });
        ws.current.addEventListener('message', handleWebsocket);
    }

    /**
     * Will be called every time an update is published by the server
     * @param event
     */
    function handleWebsocket(event) {
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
    }

    function stopWebsocket() {
        if (ws.current) {
            ws.current.close();
        }
    }

    function onShelfEdit(data) {
        message.open({
            key: 'shelf-edit-loader',
            type: 'loading',
            content: 'Updating shelf...',
            duration: 0,
        });
        setEditConfirmLoading(true);
        api.shelves.edit(shelfId, data.name).then(
            res => {
                message.open({
                    key: 'shelf-edit-loader',
                    type: 'success',
                    content: 'Shelf updated successfully',
                    duration: 2.5,
                });
                const newShelves = shelves;
                let i = newShelves.findIndex(s => s.id === res.data.id);
                newShelves[i].name = res.data.name;
                setShelves([...newShelves]);
                setEditConfirmLoading(false);
                setEditOpen(false);
            },
            err => console.error(err)
        );
    }

    function onDeleteOpen() {
        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: 'Are you sure you want to delete this shelf?',
            content: getCurrentShelf().name,
            onOk() {
                message.open({
                    key: 'shelf-delete-loader',
                    type: 'loading',
                    content: 'Deleting shelf...',
                    duration: 0,
                });
                return api.shelves.delete(shelfId).then(
                    res => {
                        message.open({
                            key: 'shelf-delete-loader',
                            type: 'success',
                            content: 'Shelf deleted successfully',
                            duration: 2.5,
                        });
                        navigate('/library/shelves');
                        refreshShelves();
                    },
                    err => console.error(err)
                );
            },
        });
    }

    const items = [];
    for (const item of content) {
        let children = <Empty/>;
        if (item.books.length > 0) {
            children = <Row
                style={{
                    paddingLeft: '8px',
                    paddingRight: '8px',
                    paddingBottom: '16px',
                    marginRight: 0,
                    marginLeft: 0
                }}
                gutter={[8, 8]}>
                {item.books.map(book =>
                    <Col key={book.id} style={{ width: '165px' }}>
                        <LibraryItem key={book.id} book={book}/>
                    </Col>
                )}
            </Row>;
        }
        items.push({
            key: item.folder,
            label: <Space><span>{item.folder}</span><Badge count={item.books.length}/></Space>,
            children: children,
        });
    }

    return <SpinComponent loading={loading}>
        <Helmet>
            <title>{getCurrentShelf().name}</title>
        </Helmet>
        <Modal
            open={editOpen}
            title={<span>Edit shelf</span>}
            onCancel={() => setEditOpen(false)}
            destroyOnHidden
            okButtonProps={{
                autoFocus: true,
                htmlType: 'submit',
            }}
            confirmLoading={editConfirmLoading}
            modalRender={(dom) =>
                <Form
                    form={editForm}
                    layout="vertical"
                    name="form_in_modal"
                    initialValues={getCurrentShelf()}
                    clearOnDestroy={true}
                    onFinish={(data) => onShelfEdit(data)}>
                    {dom}
                </Form>
            }
        >
            <Form.Item
                label="Path"
                name="path"
                extra="Insert a folder without the / at the end."
                rules={[
                    {
                        required: true,
                        message: 'Please input the shelf path!',
                    },
                ]}>
                <Input placeholder="Shelf path" disabled={true}/>
            </Form.Item>
            <Form.Item
                label="Name"
                name="name"
                rules={[
                    {
                        required: true,
                        message: 'Please input the shelf name!',
                    },
                ]}>
                <Input placeholder="Shelf name"/>
            </Form.Item>
        </Modal>
        <Tabs
            tabBarExtraContent={{
                left: <Space style={{ marginLeft: '16px', marginRight: '16px' }}>
                    <Button
                        type="text"
                        icon={collapsed ? <MenuUnfoldOutlined/> : <MenuFoldOutlined/>}
                        onClick={() => setCollapsed(!collapsed)}
                    />
                </Space>,
                right: <Space style={{ marginLeft: '16px', marginRight: '16px' }}>
                    <Tooltip title={'Edit shelf'}>
                        <Button shape="circle" color="primary" variant="outlined" icon={<EditOutlined/>}
                                onClick={() => setEditOpen(true)}
                        />
                    </Tooltip>
                    <Tooltip title={'Delete shelf'}>
                        <Button shape="circle" color="danger" variant="outlined" icon={<DeleteOutlined/>}
                                onClick={() => onDeleteOpen()}
                        />
                    </Tooltip>
                </Space>
            }}
            items={items}
        />
    </SpinComponent>;
}
