import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { formatBytes, formatDateTimeFromTimestamp } from "@CoreBundle/format-utils";
import { InfoCircleOutlined } from "@ant-design/icons";
import { Descriptions } from "antd";
import React from "react";

/**
 * Displays basic filesystem attributes including MIME type, file extension, size, and date.
 */
export default function FilePreviewInfo() {
    const { selectedFile } = useFileManager();

    const information = React.useMemo(() => {
        const out = [
            {
                key: 'type',
                label: 'Type',
                children: selectedFile.type,
            }
        ];

        if (selectedFile.type !== 'folder') {
            out.push(
                {
                    key: 'extension',
                    label: 'Extension',
                    children: selectedFile.extension,
                },
                {
                    key: 'size',
                    label: 'Size',
                    children: formatBytes(selectedFile.size),
                }
            );
        }

        out.push({
            key: 'date',
            label: 'Date',
            children: formatDateTimeFromTimestamp(selectedFile.date),
        });

        return out;
    }, [selectedFile]);

    return (
        <Descriptions
            title={<><InfoCircleOutlined/> <span>Information</span></>}
            items={information}
            column={2}
            styles={{ title: { textAlign: 'center' }, root: {paddingLeft: 8, paddingRight: 8 } }}
        />
    );
}
