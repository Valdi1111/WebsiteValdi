import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { MoreOutlined } from "@ant-design/icons";
import { Descriptions, Divider, Tag } from "antd";
import React from "react";
import { isArray } from "chart.js/helpers";

export default function FilePreviewExtraInfo() {
    const [extra, setExtra] = React.useState([]);
    const { api, info, selectedFile } = useFileManager();

    React.useEffect(() => {
        setExtra([]);
        if (!info || !info.features.meta[selectedFile.type]) {
            return
        }
        api
            .withErrorHandling()
            .fmMeta(selectedFile.id)
            .then(res => {
                setExtra(res.data.map(item => {
                    let children = item.value;
                    if (isArray(item.value)) {
                        children = <>{item.value.map((item) => <Tag key={item}>{item}</Tag>)}</>;
                    }
                    return {
                        key: item.label,
                        label: item.label,
                        children: children,
                    };
                }));
            });
    }, [selectedFile.id, info]);

    if (!extra.length) {
        return <></>;
    }

    return <>
        <Divider size="small"/>
        <Descriptions
            title={<><MoreOutlined/> <span>Extra info</span></>}
            items={extra}
            column={2}
            styles={{ title: { textAlign: 'center' } }}
        />
    </>;

}