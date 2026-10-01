import FilePreviewContent from "@CoreBundle/components/file-manager/preview/FilePreviewContent";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Button, ConfigProvider, Divider, Flex, Image, Space, Typography } from "antd";
import { CloseOutlined, DownloadOutlined, LinkOutlined } from "@ant-design/icons";
import React from "react";
import FilePreviewInfo from "@CoreBundle/components/file-manager/preview/FilePreviewInfo";
import FilePreviewExtraInfo from "@CoreBundle/components/file-manager/preview/FilePreviewExtraInfo";

/**
 * Side pane (desktop) or bottom drawer (mobile) displaying file details,
 * media viewer/player, and metadata.
 */
export default function FilePreview() {
    const { api, selectedFile, setShowPreview } = useFileManager();

    // Trigger direct file download / opening in a separate browser tab
    const onOpenFile = React.useCallback(() => {
        if (!selectedFile) return;
        const link = document.createElement('a');
        link.href = api.fmDirectUrl(selectedFile.id);
        link.target = '_blank';
        link.click();
        link.remove();
    }, [api, selectedFile]);

    // Fallback placeholder when no file is selected
    if (!selectedFile) {
        return (
            <Flex
                style={{ paddingLeft: 8, paddingRight: 8, height: '100%' }}
                justify="center"
                align="center"
                vertical
            >
                <Image src={api.fmIconUrl()} alt="logo" style={{ maxWidth: '100%', maxHeight: '100%' }}/>
            </Flex>
        );
    }

    return (
        <Flex style={{ paddingLeft: 16, paddingRight: 16, height: '100%', overflowY: 'auto' }} vertical>
            {/* Header bar: Close button on the far left, file title, and right-aligned actions */}
            <Flex justify="space-between" align="center" gap="small" style={{ width: '100%', paddingTop: 8 }}>
                <Flex align="center" gap="small" style={{ minWidth: 0, flex: 1 }}>
                    {/* Close preview button available on both desktop and mobile */}
                    <Button
                        type="text"
                        icon={<CloseOutlined />}
                        title="Close preview"
                        onClick={() => setShowPreview(false)}
                        style={{ flexShrink: 0 }}
                    />
                    {/* File title with ellipsis truncation */}
                    <Typography.Title level={4} style={{ marginBottom: 0, minWidth: 0 }} ellipsis>
                        {selectedFile.title}
                    </Typography.Title>
                </Flex>

                {/* Right-aligned action buttons */}
                <Space size="small" style={{ flexShrink: 0 }}>
                    {selectedFile.type !== 'folder' && (
                        <>
                            {/* Open file in new tab */}
                            <Button
                                type="text"
                                icon={<LinkOutlined />}
                                title="Open file"
                                onClick={onOpenFile}
                            />
                            {/* Download file button */}
                            <Button
                                type="text"
                                icon={<DownloadOutlined />}
                                title="Download"
                                onClick={() => {
                                    console.debug("Downloading", selectedFile.id);
                                    api
                                        .withErrorHandling()
                                        .fmDownload(selectedFile.id);
                                }}
                            />
                        </>
                    )}
                </Space>
            </Flex>

            <Divider style={{ margin: '12px 0' }} />

            {/* Dynamic media player or icon content preview */}
            <FilePreviewContent />

            {/* File properties and extended metadata */}
            <ConfigProvider
                theme={{
                    components: {
                        Descriptions: {
                            titleMarginBottom: 8,
                            itemPaddingBottom: 8,
                        },
                    },
                }}
            >
                <FilePreviewInfo />
                <FilePreviewExtraInfo />
            </ConfigProvider>
        </Flex>
    );
}