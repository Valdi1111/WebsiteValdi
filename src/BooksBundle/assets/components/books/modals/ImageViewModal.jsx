import React from 'react';
import { Image, App } from 'antd';
import { CopyOutlined, DownloadOutlined } from '@ant-design/icons';

export default function ImageViewModal({ open, src, alt, onClose }) {
    const { message } = App.useApp();

    // Prevent rendering when no image source is provided
    if (!src) {
        return null;
    }

    // Copy original image data to clipboard
    const handleCopy = async () => {
        try {
            const response = await fetch(src);
            const blob = await response.blob();

            // Direct Clipboard API copy for supported PNG format
            if (blob.type === 'image/png') {
                await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
            } else {
                // Canvas fallback to ensure PNG data for JPEG/WebP formats
                const img = new window.Image();
                img.crossOrigin = 'anonymous';
                img.src = src;
                await new Promise((resolve, reject) => {
                    img.onload = resolve;
                    img.onerror = reject;
                });

                const canvas = document.createElement('canvas');
                canvas.width = img.naturalWidth;
                canvas.height = img.naturalHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);

                await new Promise((resolve) => {
                    canvas.toBlob(async (pngBlob) => {
                        await navigator.clipboard.write([new ClipboardItem({ 'image/png': pngBlob })]);
                        resolve();
                    }, 'image/png');
                });
            }

            message.success('Image copied to clipboard');
        } catch (err) {
            console.error('Failed to copy image to clipboard', err);
            message.error('Failed to copy image to clipboard');
        }
    };

    // Download image file to user system
    const handleDownload = async () => {
        try {
            const response = await fetch(src);
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = (alt && alt.trim() !== '') ? `${alt.replace(/\s+/g, '_')}` : 'book_image';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            link.remove();
        } catch (err) {
            console.error('Failed to download image', err);
            message.error('Failed to download image');
        }
    };

    return (
        <div style={{ display: 'none' }}>
            <Image
                src={src}
                alt={alt || 'Image from book'}
                preview={{
                    open: open,
                    onOpenChange: (isOpen) => {
                        if (!isOpen) {
                            onClose();
                        }
                    },
                    // Inject custom buttons directly inside the actions toolbar container
                    actionsRender: (originalNode) => {
                        const customButtons = [
                            <button
                                key="copy"
                                type="button"
                                className="ant-image-preview-actions-action ant-image-preview-actions-action-copy"
                                aria-label="copy"
                                onClick={handleCopy}
                            >
                                <CopyOutlined />
                            </button>,
                            <button
                                key="download"
                                type="button"
                                className="ant-image-preview-actions-action ant-image-preview-actions-action-download"
                                aria-label="download"
                                onClick={handleDownload}
                            >
                                <DownloadOutlined />
                            </button>
                        ];

                        // Clone the original actions div and append the buttons inside its children
                        if (React.isValidElement(originalNode)) {
                            const originalChildren = React.Children.toArray(originalNode.props.children);
                            return React.cloneElement(originalNode, {}, ...originalChildren, ...customButtons);
                        }

                        return originalNode;
                    },
                }}
            />
        </div>
    );
}
