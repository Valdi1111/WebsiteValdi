import { EllipsisOutlined } from "@ant-design/icons";
import { Card, Spin } from "antd";
import React from "react";

export default function LibraryItemAdder({ hasMore, loadMore, loadingMore }) {

    if (loadingMore) {
        return <Card
            styles={{ body: { display: 'none' } }}
            hoverable={true}
            cover={<div style={{ width: '100%', height: '100%', aspectRatio: '2 / 3' }}
                        className="d-flex flex-column justify-content-center align-items-center py-3">
                <Spin/>
            </div>}
            style={{ borderRadius: "10px" }}
            onClick={loadMore}
        />
    }

    if (hasMore) {
        return <Card
            styles={{ body: { display: 'none' } }}
            hoverable={true}
            cover={<div style={{ width: '100%', height: '100%', aspectRatio: '2 / 3' }}
                        className="d-flex flex-column justify-content-center align-items-center py-3">
                <EllipsisOutlined/>
            </div>}
            style={{ borderRadius: "10px" }}
            onClick={loadMore}
        />
    }

    return <></>;
}
