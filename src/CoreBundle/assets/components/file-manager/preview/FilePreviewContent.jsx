import PlyrVideoComponent from "@CoreBundle/components/PlyrVideoComponent";
import PlyrAudioComponent from "@CoreBundle/components/PlyrAudioComponent";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Image } from "antd";
import React from "react";

/**
 * Renders the appropriate media player or placeholder based on the file MIME/type category.
 */
export default function FilePreviewContent() {
    const { api, selectedFile } = useFileManager();

    if (selectedFile.type === 'image') {
        return (
            <Image
                style={{ aspectRatio: '1 / 1', objectFit: 'contain' }}
                src={api.fmDirectUrl(selectedFile.id, true)}
                alt={selectedFile.title}
            />
        );
    }

    if (selectedFile.type === 'video') {
        return (
            <PlyrVideoComponent
                src={api.fmDirectUrl(selectedFile.id, true)}
                type={"video/" + selectedFile.extension}
            />
        );
    }

    if (selectedFile.type === 'audio') {
        return (
            <PlyrAudioComponent
                src={api.fmDirectUrl(selectedFile.id, true)}
                type={"audio/" + selectedFile.extension}
            />
        );
    }

    // Default icon fallback for non-media document formats
    return <Image src={api.fmIconUrl('big', selectedFile.type, selectedFile.extension)} alt="logo"/>;
}