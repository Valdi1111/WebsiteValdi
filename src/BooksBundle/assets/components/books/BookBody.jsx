import SpinComponent from "@CoreBundle/components/SpinComponent";
import { Flex, Layout } from "antd";
import React from "react";

export default function BookBody({ loading }) {

    return <Layout.Content style={{ height: '100%' }}>
        <Flex id="book-view" style={{ height: '100%' }}/>
        <SpinComponent loading={loading} size="large"/>
    </Layout.Content>;

}
