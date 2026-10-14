import { Button, Empty, Flex, List, Popconfirm, Typography } from "antd";
import { DeleteOutlined, EditOutlined } from "@ant-design/icons";
import BookContext from "@BooksBundle/components/books/BookContext";
import React from "react";

export default function ContentNotes() {
    const {
        notes,
        deleteNote,
        setNoteModal,
        navigateTo,
        setContentsDrawerOpen
    } = React.useContext(BookContext);

    const handleJump = (cfi) => {
        navigateTo(cfi);
        setContentsDrawerOpen(false);
    };

    const handleEdit = (e, item) => {
        e.stopPropagation();
        setNoteModal({
            open: true,
            id: item.id,
            cfi: item.cfi,
            text: item.text || '',
            note: item.note || '',
            color: item.color || '#ffeb3b',
            chapter: item.chapter || '',
            sectionIndex: item.sectionIndex
        });
    };

    return (
        <Flex vertical style={{ height: '100%', paddingLeft: 16, paddingRight: 16, paddingBottom: 16 }} gap="middle">
            <Flex justify="space-between" align="center">
                <Typography.Text strong>Notes & Highlights ({notes?.length || 0})</Typography.Text>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Select text in the book to add a note
                </Typography.Text>
            </Flex>

            {(!notes || notes.length === 0) ? (
                <Flex justify="center" align="center" style={{ flex: 1, padding: 24 }}>
                    <Empty description="No notes yet" image={Empty.PRESENTED_IMAGE_SIMPLE} />
                </Flex>
            ) : (
                <List
                    style={{ flex: 1, overflowY: 'auto' }}
                    size="small"
                    bordered
                    dataSource={notes}
                    renderItem={(item) => (
                        <List.Item
                            style={{ cursor: 'pointer' }}
                            onClick={() => handleJump(item.cfi)}
                            actions={[
                                <Button
                                    key="edit"
                                    type="text"
                                    size="small"
                                    icon={<EditOutlined />}
                                    onClick={(e) => handleEdit(e, item)}
                                />,
                                <Popconfirm
                                    key="delete"
                                    title="Delete this note?"
                                    onConfirm={(e) => {
                                        e?.stopPropagation();
                                        deleteNote(item);
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
                                title={
                                    <Flex align="center" gap="small">
                                        <span
                                            style={{
                                                display: 'inline-block',
                                                width: 12,
                                                height: 12,
                                                borderRadius: '50%',
                                                backgroundColor: item.color || '#ffeb3b'
                                            }}
                                        />
                                        <Typography.Text ellipsis style={{ maxWidth: 220 }}>
                                            {item.chapter || "Highlight"}
                                        </Typography.Text>
                                    </Flex>
                                }
                                description={
                                    <Flex vertical gap={4}>
                                        {item.text && (
                                            <Typography.Paragraph
                                                italic
                                                ellipsis={{ rows: 2 }}
                                                style={{
                                                    margin: 0,
                                                    paddingLeft: 8,
                                                    borderLeft: `3px solid ${item.color || '#ffeb3b'}`
                                                }}
                                            >
                                                "{item.text}"
                                            </Typography.Paragraph>
                                        )}
                                        {item.note && (
                                            <Typography.Text>{item.note}</Typography.Text>
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
