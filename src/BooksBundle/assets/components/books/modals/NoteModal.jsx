import React from "react";
import { Modal, Input, Radio, Flex, Typography, Button } from "antd";
import { HIGHLIGHT_COLORS } from "@BooksBundle/utils/bookOverlayer";

export default function NoteModal({ noteModal, setNoteModal, onSave, onDelete }) {
    const handleClose = () => {
        setNoteModal(prev => ({ ...prev, open: false }));
    };

    return (
        <Modal
            title={noteModal.id ? "Edit Note" : "Add Note & Highlight"}
            open={noteModal.open}
            onOk={onSave}
            onCancel={handleClose}
            destroyOnHidden
            footer={[
                noteModal.id && (
                    <Button key="delete" danger onClick={() => onDelete(noteModal)}>
                        Delete
                    </Button>
                ),
                <Button key="cancel" onClick={handleClose}>
                    Cancel
                </Button>,
                <Button key="save" type="primary" onClick={onSave}>
                    Save
                </Button>
            ]}
        >
            <Flex vertical gap="middle">
                {noteModal.text && (
                    <Typography.Paragraph
                        italic
                        ellipsis={{ rows: 3, expandable: true }}
                        style={{
                            borderLeft: `4px solid ${noteModal.color}`,
                            paddingLeft: 12,
                            margin: 0
                        }}
                    >
                        "{noteModal.text}"
                    </Typography.Paragraph>
                )}
                <Flex align="center" gap="small" wrap="wrap">
                    <Typography.Text strong>Color:</Typography.Text>
                    <Radio.Group
                        value={noteModal.color}
                        onChange={e => setNoteModal(prev => ({ ...prev, color: e.target.value }))}
                    >
                        {HIGHLIGHT_COLORS.map(c => (
                            <Radio.Button
                                key={c.value}
                                value={c.value}
                                style={{ backgroundColor: c.value, color: '#000', marginRight: 6 }}
                            >
                                {c.label}
                            </Radio.Button>
                        ))}
                    </Radio.Group>
                </Flex>
                <Input.TextArea
                    rows={4}
                    placeholder="Write a note (optional)..."
                    value={noteModal.note}
                    onChange={e => setNoteModal(prev => ({ ...prev, note: e.target.value }))}
                />
            </Flex>
        </Modal>
    );
}
