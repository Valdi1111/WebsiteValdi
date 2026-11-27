import { Image } from "antd";
import React, { useCallback, useEffect, useState } from "react";

/**
 * Short monogram of an item name: the first two letters of a single word, otherwise the initials of the first two words.
 */
export function getInitials(name) {
    if (!name) return "?";
    const words = name.trim().split(/\s+/);
    if (words.length === 1) {
        return words[0].substring(0, 2).toUpperCase();
    }
    return (words[0][0] + words[1][0]).toUpperCase();
}

const IMAGE_STYLE = { width: "100%", height: "100%", objectFit: "cover", display: "block" };
// The antd wrapper is an inline-block: make it fill the circle. Its default cover (which also set the cursor) is disabled
const PREVIEW_ROOT_STYLE = { display: "block", width: "100%", height: "100%", cursor: "pointer" };

/**
 * Tooltip state for a trigger that contains a previewable PullItemImage: spread tooltipProps on the Tooltip and pass
 * onPreviewOpenChange to the image. The tooltip stays closed while the preview is open (on touch a tap opens both,
 * and pointer events in the preview reach the trigger through the React tree) and is not left open once it closes.
 */
export function usePreviewTooltip() {
    const [hovered, setHovered] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);

    const onPreviewOpenChange = useCallback((open) => {
        setPreviewOpen(open);
        if (!open) {
            setHovered(false);
        }
    }, []);

    return {
        tooltipProps: { open: hovered && !previewOpen, onOpenChange: setHovered },
        onPreviewOpenChange,
    };
}

/**
 * Round image of a pulled item (character, weapon...). Shows the initials of the name when there is no icon or it
 * fails to load, or the placeholder text when given (unknown pulls). When preview is on, a click on the image opens
 * the full artwork (previewUrl, the icon when missing), downloaded only when the preview opens.
 *
 * - size: diameter in px, or a CSS length (default "100%", to fill a parent circle)
 * - style: look of the circle (border, background, text colour and size, opacity...)
 * - preview: false where a click must do something else (e.g. a Select option)
 * - onPreviewOpenChange: called with the preview open state, see usePreviewTooltip
 */
export default function PullItemImage({
    iconUrl,
    previewUrl,
    name,
    placeholder,
    size = "100%",
    preview = true,
    onPreviewOpenChange,
    style,
}) {
    const [imgError, setImgError] = useState(false);

    useEffect(() => {
        setImgError(false);
    }, [iconUrl]);

    const showImage = !placeholder && Boolean(iconUrl) && !imgError;

    let content = placeholder ?? getInitials(name);
    if (showImage && preview) {
        content = (
            <Image
                src={iconUrl}
                alt={name}
                loading="lazy"
                onError={() => setImgError(true)}
                style={IMAGE_STYLE}
                styles={{ root: PREVIEW_ROOT_STYLE }}
                preview={{
                    src: previewUrl || iconUrl,
                    // In antd 6 the hover overlay is "cover" ("mask" is the backdrop of the preview)
                    cover: false,
                    onOpenChange: onPreviewOpenChange,
                }}
            />
        );
    } else if (showImage) {
        content = (
            <img
                src={iconUrl}
                alt={name}
                loading="lazy"
                onError={() => setImgError(true)}
                style={IMAGE_STYLE}
            />
        );
    }

    return (
        <div
            style={{
                width: size,
                height: size,
                borderRadius: "50%",
                overflow: "hidden",
                flexShrink: 0,
                display: "inline-flex",
                alignItems: "center",
                justifyContent: "center",
                ...style,
            }}
        >
            {content}
        </div>
    );
}
