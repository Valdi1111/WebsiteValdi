import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { MoreOutlined } from "@ant-design/icons";
import { Descriptions, Tag } from "antd";
import React from "react";
import { isArray } from "chart.js/helpers";

/**
 * Asynchronously loads and renders extended metadata (e.g., EXIF, audio tags, video codec data)
 * if enabled and supported for the current file type.
 */
export default function FilePreviewExtraInfo() {
    const [extra, setExtra] = React.useState([]);
    const { api, info, selectedFile } = useFileManager();

    React.useEffect(() => {
        setExtra([]);
        // Verify that server feature flags support metadata for this file type
        if (!info || !info.features.meta[selectedFile.type]) {
            return;
        }

        api
            .withErrorHandling()
            .fmMeta(selectedFile.id)
            .then(res => {
                setExtra(res.data.map(item => {
                    let children = item.value;
                    if (isArray(item.value)) {
                        children = <>{item.value.map((val) => <Tag key={val}>{val}</Tag>)}</>;
                    }
                    return {
                        key: item.label,
                        label: item.label,
                        children: children,
                    };
                }));
            });
    }, [selectedFile.id, selectedFile.type, info, api]);

    if (!extra.length) {
        return <></>;
    }

    return (
        <Descriptions
            title={<><MoreOutlined/> <span>Extra info</span></>}
            items={extra}
            column={2}
            styles={{ title: { textAlign: 'center' }, root: {paddingLeft: 8, paddingRight: 8 } }}
        />
    );
}
