import SpinComponent from "@CoreBundle/components/SpinComponent";
import { Flex, Layout, theme as antdTheme } from "antd";
import React from "react";

export default function BookBody({ loading }) {
    const { token: { colorBgElevated } } = antdTheme.useToken();

    return <Layout.Content id="book-body" style={{ height: '100%', background: colorBgElevated }}>
        <Flex id="book-view" style={{ height: '100%' }} justify="center"/>
        <SpinComponent loading={loading} size="large"/>
    </Layout.Content>;

}
