import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import FilePreviewContent from "@CoreBundle/components/file-manager/preview/FilePreviewContent";
import { Button, Descriptions, Divider, Flex, Image, Typography } from "antd";
import { formatBytes, formatDateTimeFromTimestamp } from "@CoreBundle/utils";
import { DownloadOutlined } from "@ant-design/icons";
import React from "react";

export default function FilePreview() {
    const { api, files, selectedKey } = useFileManager();

    const row = React.useMemo(() => {
        const f = files.find(f => f.key === selectedKey);
        if (f && f.title) {
            f.typeExtension = f.title.split('.').pop();
        }
        return f;
    }, [files, selectedKey]);

    const items = React.useMemo(() => {
        if (!row) {
            return [];
        }
        return [
            {
                key: 'type',
                label: 'Type',
                children: <p>{row.type}</p>,
            },
            {
                key: 'size',
                label: 'Size',
                children: <p>{formatBytes(row.size)}</p>,
            },
            {
                key: 'date',
                label: 'Date',
                children: <p>{formatDateTimeFromTimestamp(row.date)}</p>,
            },
        ];
    }, [row]);

    if (!row) {
        return <Flex
            style={{ paddingLeft: '8px', paddingRight: '8px', height: '100%' }}
            justify="center"
            align="center"
            vertical>
            <Image src={api.fmIconUrl()} alt="logo" style={{maxWidth: '100%', maxHeight: '100%'}}/>
        </Flex>
    }

    return <Flex style={{ paddingLeft: '16px', paddingRight: '16px' }} vertical>
        <Flex justify="space-between" gap="small" style={{ width: '100%' }}>
            <Typography.Title level={4} style={{ marginBottom: 0 }} ellipsis>
                {row.title}
            </Typography.Title>
            <Button
                style={{ paddingLeft: "8px", paddingRight: "8px" }}
                icon={<DownloadOutlined/>}
                onClick={() => {
                    console.debug("Downloading", row.key);
                    api.fmDownload(row.key);
                }}
            />
        </Flex>
        <Divider size="small"/>
        <FilePreviewContent row={row}/>
        <Divider size="small"/>
        <Descriptions title="Information" styles={{title: {textAlign: 'center'}}} items={items} column={2}/>
    </Flex>

}