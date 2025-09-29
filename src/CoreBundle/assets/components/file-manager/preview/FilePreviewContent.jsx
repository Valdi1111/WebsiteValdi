import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Image } from "antd";
import React from "react";

export default function FilePreviewContent({ row }) {
    const { api } = useFileManager();

    if (row.type === 'image') {
        return <Image
            style={{ aspectRatio: '1 / 1', objectFit: 'contain'}}
            src={api.fmDirectUrl(row.key, true)}
            alt="logo"
        />;
    }

    return <Image src={api.fmIconUrl('big', row.type, row.typeExtension)} alt="logo"/>;

}