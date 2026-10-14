import { Button, Empty, Flex, List, Popconfirm, Tag, Tooltip, Typography } from "antd";
import { AimOutlined, DeleteOutlined, EnvironmentOutlined, PlusOutlined } from "@ant-design/icons";
import BookContext from "@BooksBundle/components/books/BookContext";
import React from "react";

export default function ContentBookmarks() {
    const {
        bookmarks,
        addBookmark,
        deleteBookmark,
         setBookmarkPickMode,
        navigateTo,
        setContentsDrawerOpen
    } = React.useContext(BookContext);

    const handleJump = (cfi) => {
        navigateTo(cfi);
        setContentsDrawerOpen(false);
    };

    const startParagraphPick = () => {
        setContentsDrawerOpen(false);
        setBookmarkPickMode(true);
    };

    return (
        <Flex vertical style={{ height: '100%', paddingLeft: 16, paddingRight: 16, paddingBottom: 16 }} gap="middle">
            <Flex justify="space-between" align="center" wrap="wrap" gap="small">
                <Typography.Text strong>Saved Bookmarks ({bookmarks?.length || 0})</Typography.Text>
                <Flex gap="small">
                    <Tooltip title="Click a specific paragraph in the book to bookmark it (or use Alt+Click)">
                        <Button
                            size="small"
                            icon={<AimOutlined />}
                            onClick={startParagraphPick}
                        >
                            Pick Paragraph
                        </Button>
                    </Tooltip>
                    <Button
                        type="primary"
                        size="small"
                        icon={<PlusOutlined />}
                        onClick={() => addBookmark()}
                    >
                        Current View
                    </Button>
                </Flex>
            </Flex>

            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                Tip: You can also select any text in the book or Alt+Click a paragraph to bookmark an exact line.
            </Typography.Text>

            {(!bookmarks || bookmarks.length === 0) ? (
                <Flex justify="center" align="center" style={{ flex: 1, padding: 24 }}>
                    <Empty description="No bookmarks yet" image={Empty.PRESENTED_IMAGE_SIMPLE} />
                </Flex>
            ) : (
                <List
                    style={{ flex: 1, overflowY: 'auto' }}
                    size="small"
                    bordered
                    dataSource={bookmarks}
                    renderItem={(item) => (
                        <List.Item
                            style={{ cursor: 'pointer' }}
                            onClick={() => handleJump(item.cfi)}
                            actions={[
                                <Popconfirm
                                    key="delete"
                                    title="Delete this bookmark?"
                                    onConfirm={(e) => {
                                        e?.stopPropagation();
                                        deleteBookmark(item.id);
                                    }}
                                    onCancel={(e) => e?.stopPropagation()}
                                    okText="Yes"
                                    cancelText="No"
                                >
                                    <Button
                                        type="text"
                                        danger
                                        size="small"
                                        icon={<DeleteOutlined />}
                                        onClick={(e) => e.stopPropagation()}
                                    />
                                </Popconfirm>
                            ]}
                        >
                            <List.Item.Meta
                                avatar={<EnvironmentOutlined style={{ marginTop: 4, color: '#ff4d4f' }} />}
                                title={
                                    <Flex align="center" gap="small">
                                        <Typography.Text ellipsis style={{ maxWidth: 210 }}>
                                            {item.label || item.chapter || "Bookmark"}
                                        </Typography.Text>
                                        <Tag>{Math.round((item.fraction || 0) * 100)}%</Tag>
                                    </Flex>
                                }
                                description={
                                    <Flex vertical gap={2}>
                                        {item.chapter && item.label !== item.chapter && (
                                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                                {item.chapter}
                                            </Typography.Text>
                                        )}
                                        {item.created && (
                                            <Typography.Text type="secondary" style={{ fontSize: 11 }}>
                                                {new Date(item.created).toLocaleString()}
                                            </Typography.Text>
                                        )}
                                    </Flex>
                                }
                            />
                        </List.Item>
                    )}
                />
            )}
        </Flex>
    );
}
