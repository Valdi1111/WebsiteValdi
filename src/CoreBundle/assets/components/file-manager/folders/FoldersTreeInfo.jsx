import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { formatBytes } from "@CoreBundle/format-utils";
import { Flex, Progress } from "antd";
import React from "react";

export default function FoldersTreeInfo() {
    const { info } = useFileManager();

    if (!info) {
        return <></>;
    }

    let percent = 0;
    if (info.stats.total > 0) {
        percent = info.stats.used / info.stats.total * 100;
    }

    return <Flex vertical gap="small" style={{ padding: "10px" }}>
        <Progress percent={percent} showInfo={false}/>
        <span>{formatBytes(info.stats.used)} of {formatBytes(info.stats.total)} used</span>
    </Flex>;

}