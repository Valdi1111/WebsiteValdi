import FilePreviewContent from "@CoreBundle/components/file-manager/preview/FilePreviewContent";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import {Button, Card, ConfigProvider, Divider, Flex, Image, Space, Tooltip, Typography} from "antd";
import { CloseOutlined, DownloadOutlined, LinkOutlined } from "@ant-design/icons";
import React from "react";
import FilePreviewInfo from "@CoreBundle/components/file-manager/preview/FilePreviewInfo";
import FilePreviewExtraInfo from "@CoreBundle/components/file-manager/preview/FilePreviewExtraInfo";

const { Text } = Typography;

/**
 * Side pane (desktop Card) or bottom drawer (mobile) displaying file details,
 * media viewer/player, and metadata.
 */
export default function FilePreview() {
    const { api, selectedFile, setShowPreview, isMobile } = useFileManager();

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
        const placeholderContent = (
            <Flex
                style={{ height: '100%', width: '100%' }}
                justify="center"
                align="center"
                vertical
            >
                <Image src={api.fmIconUrl()} alt="logo" preview={false} style={{ maxWidth: 120, opacity: 0.4 }}/>
            </Flex>
        );

        if (isMobile) {
            return placeholderContent;
        }

        return (
            <Card
                styles={{
                    body: {
                        padding: 16,
                        height: '100%',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                    },
                }}
                style={{
                    height: '100%',
                    display: 'flex',
                    flexDirection: 'column',
                    minHeight: 0,
                    boxSizing: 'border-box',
                }}
            >
                {placeholderContent}
            </Card>
        );
    }

    // Action buttons for open and download
    const actionButtons = (
        <Space size={4} style={{ flexShrink: 0 }}>
            {selectedFile.type !== 'folder' && (
                <>
                    <Tooltip title="Open file">
                        <Button
                            type="text"
                            icon={<LinkOutlined />}
                            onClick={onOpenFile}
                        />
                    </Tooltip>
                    <Tooltip title="Download">
                        <Button
                            type="text"
                            icon={<DownloadOutlined />}
                            onClick={() => {
                                console.debug("Downloading", selectedFile.id);
                                api
                                    .withErrorHandling()
                                    .fmDownload(selectedFile.id);
                            }}
                        />
                    </Tooltip>
                </>
            )}
            <Tooltip title="Close preview">
                <Button
                    type="text"
                    icon={<CloseOutlined />}
                    onClick={() => setShowPreview(false)}
                />
            </Tooltip>
        </Space>
    );

    // Inner scrollable body with media preview and descriptions
    const bodyContent = (
        <Flex vertical style={{ width: '100%' }}>
            {/* Dynamic media player or icon content preview */}
            <div style={{ width: '100%', display: 'flex', justifyContent: 'center' }}>
                <FilePreviewContent />
            </div>

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
                <Divider size="small"/>
                <FilePreviewInfo />
                <Divider size="small"/>
                <FilePreviewExtraInfo />
            </ConfigProvider>
        </Flex>
    );

    // Mobile layout: Rendered directly inside the Drawer without Card wrapper
    if (isMobile) {
        return (
            <Flex style={{ height: '100%', overflowY: 'auto' }} vertical>
                <Flex justify="space-between" align="center" gap="small" style={{ width: '100%', paddingTop: 8 }}>
                    <Text strong ellipsis style={{ fontSize: 16, flex: 1, minWidth: 0 }}>
                        {selectedFile.title}
                    </Text>
                    {actionButtons}
                </Flex>
                <Divider size="small"/>
                {bodyContent}
            </Flex>
        );
    }

    // Desktop layout: Card matching FoldersTree and FilesTable
    return (
        <Card
            styles={{
                header: {
                    height: 'auto',
                    padding: '14px 16px',
                    whiteSpace: 'normal',
                    flexShrink: 0,
                },
                body: {
                    padding: 8,
                    flex: 1,
                    minHeight: 0,
                    overflowY: 'auto',
                    overflowX: 'hidden',
                },
            }}
            style={{
                height: '100%',
                display: 'flex',
                flexDirection: 'column',
                minHeight: 0,
                boxSizing: 'border-box',
            }}
            title={
                <Flex justify="space-between" align="center" gap="small" style={{ width: '100%' }}>
                    <Text strong ellipsis style={{ fontSize: 15, flex: 1, minWidth: 0 }}>
                        {selectedFile.title}
                    </Text>
                    {actionButtons}
                </Flex>
            }
        >
            {bodyContent}
        </Card>
    );
}
