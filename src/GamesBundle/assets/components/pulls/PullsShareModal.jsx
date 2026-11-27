import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { CopyOutlined, DownloadOutlined, PictureOutlined } from "@ant-design/icons";
import { App, Button, Checkbox, Modal, Select, Space, Spin, Typography } from "antd";
import React, { useEffect, useMemo, useState } from "react";

const { Text } = Typography;

const ALL_BANNERS = "";
const FALLBACK_FILENAME = "pulls.png";

// Copying an image needs the async Clipboard API with ClipboardItem (missing on older browsers)
const canCopyImage = () =>
    typeof window !== "undefined" && typeof window.ClipboardItem !== "undefined" && Boolean(navigator.clipboard?.write);

/**
 * File name of the card, from the Content-Disposition of the response.
 */
function filenameFromHeaders(headers) {
    const disposition = headers?.["content-disposition"] || "";
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);

    return match ? decodeURIComponent(match[1]) : FALLBACK_FILENAME;
}

/**
 * Builds the shareable image of the pulls on the server (one banner or all of them) and offers it as a download or
 * on the clipboard. Changing an option drops the image generated with the previous ones.
 *
 * @param {Object} props
 * @param {boolean} props.open
 * @param {() => void} props.onClose
 * @param {string|number} props.accountId
 * @param {string|number} props.gameProfileId
 * @param {Array<{key: string, name: string, total_pulls: number}>} props.banners Banners of the overview
 * @param {string|null} props.initialBannerKey Banner selected when the modal opens, null for all of them
 * @param {Object} props.rarityConfig
 */
export default function PullsShareModal({
    open,
    onClose,
    accountId,
    gameProfileId,
    banners = [],
    initialBannerKey = null,
    rarityConfig,
}) {
    const api = useBackendApi();
    const { message } = App.useApp();
    const sLabel = rarityConfig?.s?.label || "S";

    const [bannerKey, setBannerKey] = useState(ALL_BANNERS);
    const [showAll, setShowAll] = useState(false);
    const [showPlayer, setShowPlayer] = useState(false);
    const [generating, setGenerating] = useState(false);
    // { blob, url, filename } of the last image generated with the current options
    const [image, setImage] = useState(null);

    const bannerOptions = useMemo(
        () => [
            { label: "All banners", value: ALL_BANNERS },
            ...banners
                .filter((banner) => banner.total_pulls > 0)
                .map((banner) => ({ label: banner.name, value: banner.key })),
        ],
        [banners]
    );

    // Each opening starts from the banner on screen
    useEffect(() => {
        if (open) {
            const known = bannerOptions.some((option) => option.value === initialBannerKey);
            setBannerKey(initialBannerKey && known ? initialBannerKey : ALL_BANNERS);
        }
    }, [open, initialBannerKey, bannerOptions]);

    // An image generated with other options is stale
    useEffect(() => {
        setImage(null);
    }, [bannerKey, showAll, showPlayer, open]);

    // The object URL lives as long as its image
    useEffect(() => {
        return () => {
            if (image?.url) {
                URL.revokeObjectURL(image.url);
            }
        };
    }, [image]);

    const handleGenerate = () => {
        setGenerating(true);
        api.withErrorHandling()
            .gameProfiles()
            .getPullsCard(accountId, gameProfileId, {
                banner: bannerKey || undefined,
                all: showAll ? 1 : undefined,
                player: showPlayer ? 1 : undefined,
            })
            .then((res) => {
                const blob = res.data;
                setImage({ blob, url: URL.createObjectURL(blob), filename: filenameFromHeaders(res.headers) });
            })
            .catch(() => {
                // Shown by withErrorHandling
            })
            .finally(() => setGenerating(false));
    };

    const handleCopy = () => {
        if (!image) return;
        navigator.clipboard
            .write([new window.ClipboardItem({ [image.blob.type || "image/png"]: image.blob })])
            .then(() => message.success("Image copied to the clipboard"))
            .catch(() => message.error("The browser did not allow copying the image"));
    };

    return (
        <Modal
            open={open}
            onCancel={onClose}
            title={
                <Space>
                    <PictureOutlined />
                    Share as image
                </Space>
            }
            width={720}
            footer={
                <Space wrap>
                    <Button onClick={onClose}>Close</Button>
                    {image && canCopyImage() && (
                        <Button icon={<CopyOutlined />} onClick={handleCopy}>
                            Copy
                        </Button>
                    )}
                    {image && (
                        <Button type="primary" icon={<DownloadOutlined />} href={image.url} download={image.filename}>
                            Download
                        </Button>
                    )}
                    {!image && (
                        <Button type="primary" icon={<PictureOutlined />} loading={generating} onClick={handleGenerate}>
                            Generate
                        </Button>
                    )}
                </Space>
            }
            destroyOnHidden
        >
            <Space orientation="vertical" size={12} style={{ width: "100%" }}>
                <Select
                    value={bannerKey}
                    onChange={setBannerKey}
                    options={bannerOptions}
                    style={{ width: "100%" }}
                    disabled={generating}
                />
                <Checkbox checked={showAll} onChange={(e) => setShowAll(e.target.checked)} disabled={generating}>
                    Show every {sLabel} pull <Text type="secondary">(longer image)</Text>
                </Checkbox>
                <Checkbox checked={showPlayer} onChange={(e) => setShowPlayer(e.target.checked)} disabled={generating}>
                    Show nickname and UID
                </Checkbox>

                {generating && (
                    <div style={{ textAlign: "center", padding: 24 }}>
                        <Spin />
                    </div>
                )}
                {image && !generating && (
                    <div
                        style={{
                            maxHeight: "55vh",
                            overflowY: "auto",
                            borderRadius: 8,
                            border: "1px solid rgba(5, 5, 5, 0.08)",
                        }}
                    >
                        <img src={image.url} alt="Pulls card" style={{ display: "block", width: "100%" }} />
                    </div>
                )}
            </Space>
        </Modal>
    );
}
