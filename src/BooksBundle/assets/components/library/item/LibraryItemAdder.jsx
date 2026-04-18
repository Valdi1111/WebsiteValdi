import { EllipsisOutlined } from "@ant-design/icons";
import { Card, Flex, Spin } from "antd";
import React from "react";

export default function LibraryItemAdder({ hasMore, loadMore, loadingMore }) {

    if (loadingMore) {
        return <Card
            styles={{ body: { display: 'none' } }}
            cover={<Flex
                style={{ display: 'flex', width: '100%', height: '100%', aspectRatio: '2 / 3', padding: '16px 0' }}
                justify='center'
                align='center'
                vertical
            >
                <Spin/>
            </Flex>}
            onClick={loadMore}
            hoverable
        />
    }

    if (hasMore) {
        return <Card
            styles={{ body: { display: 'none' } }}
            cover={<Flex
                style={{ display: 'flex', width: '100%', height: '100%', aspectRatio: '2 / 3', padding: '16px 0' }}
                justify='center'
                align='center'
                vertical
            >
                <EllipsisOutlined/>
            </Flex>}
            onClick={loadMore}
            hoverable
        />
    }

    return <></>;
}
