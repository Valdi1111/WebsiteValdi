import FilePreviewContent from "@CoreBundle/components/file-manager/preview/FilePreviewContent";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Button, ConfigProvider, Divider, Flex, Image, Typography } from "antd";
import { DownloadOutlined } from "@ant-design/icons";
import React from "react";
import FilePreviewInfo from "@CoreBundle/components/file-manager/preview/FilePreviewInfo";
import FilePreviewExtraInfo from "@CoreBundle/components/file-manager/preview/FilePreviewExtraInfo";

export default function FilePreview() {
    const { api, selectedFile } = useFileManager();

    if (!selectedFile) {
        return <Flex
            style={{ paddingLeft: '8px', paddingRight: '8px', height: '100%' }}
            justify="center"
            align="center"
            vertical>
            <Image src={api.fmIconUrl()} alt="logo" style={{ maxWidth: '100%', maxHeight: '100%' }}/>
        </Flex>
    }

    return <Flex style={{ paddingLeft: '16px', paddingRight: '16px' }} vertical>
        <Flex justify="space-between" gap="small" style={{ width: '100%' }}>
            <Typography.Title level={4} style={{ marginBottom: 0 }} ellipsis>
                {selectedFile.title}
            </Typography.Title>
            <Button
                style={{ paddingLeft: "8px", paddingRight: "8px" }}
                icon={<DownloadOutlined/>}
                onClick={() => {
                    console.debug("Downloading", selectedFile.id);
                    api
                        .withErrorHandling()
                        .fmDownload(selectedFile.id);
                }}
            />
        </Flex>
        <Divider size="small"/>
        <FilePreviewContent/>
        <ConfigProvider theme={{
            components: {
                Descriptions: {
                    titleMarginBottom: 8,
                    itemPaddingBottom: 8,
                },
            },
        }}>
            <FilePreviewInfo/>
            <FilePreviewExtraInfo/>
        </ConfigProvider>
    </Flex>;

}