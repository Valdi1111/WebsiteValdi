import React from "react";
import { Button, Divider, Flex, Tag, Tooltip, theme as antdTheme } from "antd";
import {
    AimOutlined,
    CloseOutlined,
    CopyOutlined,
    EditOutlined,
    PushpinOutlined,
    TranslationOutlined
} from "@ant-design/icons";
import { HIGHLIGHT_COLORS } from "@BooksBundle/utils/bookOverlayer";

export default function SelectionFloatingMenu({
    selectionMenu,
    bookmarkPickMode,
    setBookmarkPickMode,
    onQuickHighlight,
    onOpenNoteModal,
    onBookmarkSelection,
    onCopySelection,
    onTranslateSelection,
    onCloseMenu
}) {
    const { token: { colorBgElevated } } = antdTheme.useToken();

    return (
        <>
            {/* Banner shown when Paragraph Bookmark Pick Mode is active */}
            {bookmarkPickMode && (
                <div
                    style={{
                        position: 'fixed',
                        top: 64,
                        left: '50%',
                        transform: 'translateX(-50%)',
                        zIndex: 1060,
                        boxShadow: '0 4px 12px rgba(0,0,0,0.2)'
                    }}
                >
                    <Tag
                        icon={<AimOutlined />}
                        color="processing"
                        style={{ padding: '6px 12px', fontSize: 13, display: 'flex', alignItems: 'center', gap: 8 }}
                    >
                        <span>Tap or click any paragraph in the book to bookmark it</span>
                        <Button
                            type="text"
                            size="small"
                            icon={<CloseOutlined />}
                            onClick={() => setBookmarkPickMode(false)}
                        />
                    </Tag>
                </div>
            )}

            {/* Floating Selection Context Menu */}
            {selectionMenu.open && (
                <div
                    style={{
                        position: 'fixed',
                        left: selectionMenu.x,
                        top: selectionMenu.y,
                        transform: selectionMenu.placement === 'top'
                            ? 'translate(-50%, -100%)'
                            : 'translate(-50%, 0)',
                        zIndex: 1050,
                        backgroundColor: colorBgElevated,
                        padding: '6px 10px',
                        borderRadius: 10,
                        boxShadow: '0 6px 16px 0 rgba(0, 0, 0, 0.28)',
                        border: '1px solid rgba(128, 128, 128, 0.25)',
                        userSelect: 'none'
                    }}
                >
                    <Flex align="center" gap={6}>
                        {/* 1-Tap Quick Highlight Color Swatches */}
                        {HIGHLIGHT_COLORS.map(c => (
                            <Tooltip key={c.value} title={`Highlight ${c.label}`}>
                                <div
                                    onClick={() => onQuickHighlight(c.value)}
                                    style={{
                                        width: 22,
                                        height: 22,
                                        borderRadius: '50%',
                                        backgroundColor: c.value,
                                        cursor: 'pointer',
                                        border: '1px solid rgba(0, 0, 0, 0.25)',
                                        flexShrink: 0
                                    }}
                                />
                            </Tooltip>
                        ))}

                        <Divider orientation="vertical" style={{ margin: '0 2px' }} />

                        {/* Add Note with comment */}
                        <Tooltip title="Add Note">
                            <Button
                                type="text"
                                size="small"
                                icon={<EditOutlined />}
                                onClick={onOpenNoteModal}
                            />
                        </Tooltip>

                        {/* Bookmark exact selected text */}
                        <Tooltip title="Bookmark Exact Selection">
                            <Button
                                type="text"
                                size="small"
                                icon={<PushpinOutlined />}
                                onClick={onBookmarkSelection}
                            />
                        </Tooltip>

                        {/* Copy to clipboard */}
                        <Tooltip title="Copy">
                            <Button
                                type="text"
                                size="small"
                                icon={<CopyOutlined />}
                                onClick={onCopySelection}
                            />
                        </Tooltip>

                        {/* Translate selected text */}
                        <Tooltip title="Translate">
                            <Button
                                type="text"
                                size="small"
                                icon={<TranslationOutlined />}
                                onClick={onTranslateSelection}
                            />
                        </Tooltip>

                        {/* Close menu */}
                        <Button
                            type="text"
                            size="small"
                            icon={<CloseOutlined />}
                            onClick={onCloseMenu}
                        />
                    </Flex>
                </div>
            )}
        </>
    );
}
