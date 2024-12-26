import { Dropdown, Space } from "antd";
import {
    CopyOutlined, DeleteOutlined,
    DownloadOutlined, EditOutlined,
    ScissorOutlined, SnippetsOutlined
} from "@ant-design/icons";
import React from "react";

export default function FilesRowDropdown({ open, setOpen, pos, setPos }) {

    const items = [
        {
            key: 'download',
            label: 'Download',
            icon: <DownloadOutlined/>,
            extra: 'Ctrl+D',
        },
        {
            type: 'divider',
        },
        {
            key: 'copy',
            label: 'Copy',
            icon: <CopyOutlined/>,
            extra: 'Ctrl+C',
        },
        {
            key: 'cut',
            label: 'Cut',
            icon: <ScissorOutlined/>,
            extra: 'Ctrl+X',
        },
        {
            key: 'paste',
            label: 'Paste',
            icon: <SnippetsOutlined/>,
            extra: 'Ctrl+V',
        },
        {
            type: 'divider',
        },
        {
            key: 'rename',
            label: 'Rename',
            icon: <EditOutlined/>,
            extra: 'Ctrl+R',
        },
        {
            key: 'delete',
            label: 'Delete',
            icon: <DeleteOutlined/>,
            extra: 'Del / ←',
        },
    ];

    function onOpenChange(open, info) {
        setOpen(open);
    }

    return <Dropdown
        overlayStyle={{ left: `${pos.x}px`, top: `${pos.y}px` }}
        open={open}
        onOpenChange={onOpenChange}
        menu={{ items }}
    >
        <div style={{ width: 0, height: 0 }}/>
    </Dropdown>;
}