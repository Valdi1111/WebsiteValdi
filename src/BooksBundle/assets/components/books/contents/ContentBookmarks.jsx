import { Empty, Flex } from "antd";
import React from "react";

export default function ContentBookmarks() {
    return (
        <Flex justify="center" align="center" style={{ height: '100%', padding: 24 }}>
            <Empty description="No bookmarks yet" image={Empty.PRESENTED_IMAGE_SIMPLE} />
        </Flex>
    );
}
