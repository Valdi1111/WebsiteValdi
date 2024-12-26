import { AppstoreOutlined, BarsOutlined, BookOutlined, EyeInvisibleOutlined, EyeOutlined } from "@ant-design/icons";
import { Button, Col, Flex, Input, Layout, Row, Segmented, Space, Splitter, Tabs } from "antd";
import FoldersTree from "@BooksBundle/components/files/FoldersTree";
import FilesList from "@BooksBundle/components/files/FilesList";
import React from "react";

const tabs = [
    {
        key: "all_books",
        label: "All books",
        children: `aaa`,
        icon: <BookOutlined />,
    },
    {
        key: "shelves",
        label: "Shelves",
        children: `aaa`,
        icon: <BookOutlined />,
    },
    {
        key: "not_in_shelves",
        label: "Not in shelves",
        children: `aaa`,
        icon: <BookOutlined />,
    },
    {
        key: "files",
        label: "files",
        children: `aaa`,
        icon: <BookOutlined />,
    },
];

export default function FileManager2() {
    const [showPreview, setShowPreview] = React.useState(false);
    const [loadingSearch, setLoadingSearch] = React.useState(false);

    function togglePreview() {
        setShowPreview(!showPreview);
    }

    function onSearch(value, _e, info) {
        setLoadingSearch(true);
        console.log(info?.source, value);
        setTimeout(() => setLoadingSearch(false), 3000);
    }

    return <Layout style={{ height: '100vh' }}>
        <Layout.Header style={{ color: '#fff', fontSize: '20px' }}>My Application Navbar</Layout.Header>
        {/*<Layout.Header>*/}
        {/*    <Tabs*/}
        {/*        defaultActiveKey="2"*/}
        {/*        items={tabs}*/}
        {/*    />*/}
        {/*</Layout.Header>*/}
        <Row justify="space-between" align="middle">
            <Col span={6}>
                <Input.Search placeholder="Search files and folders" allowClear onSearch={onSearch} loading={loadingSearch}/>
            </Col>
            <Col>
                <Space>
                    <Button icon={showPreview ? <EyeOutlined/> : <EyeInvisibleOutlined/>} onClick={togglePreview}/>
                    <Segmented options={[
                        { value: 'List', icon: <BarsOutlined/> },
                        { value: 'Kanban', icon: <AppstoreOutlined/> },
                    ]}/>
                </Space>
            </Col>
        </Row>
        <Layout.Content>
            <Splitter style={{ height: '100%' }}>
                <Splitter.Panel defaultSize="20%" min="20%" max="40%" style={{ overflow: 'auto', height: '100%' }}>
                    <FoldersTree/>
                </Splitter.Panel>
                <Splitter.Panel span={18} style={{ overflow: 'auto', height: '100%' }}>
                    <FilesList/>
                </Splitter.Panel>
            </Splitter>
        </Layout.Content>
    </Layout>;

}