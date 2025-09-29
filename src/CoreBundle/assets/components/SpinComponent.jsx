import { Flex, Spin } from "antd";
import React from "react";

export default function SpinComponent({ loading, children, size = undefined, tip = undefined }) {
    if (loading) {
        return <Flex vertical={true} align="center" justify="center" style={{ height: '100%', width: '100%' }}>
            <Spin size={size}/>
        </Flex>;
    }
    return children;
}
