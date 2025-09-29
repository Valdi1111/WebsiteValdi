import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { Button, Flex, Input, Segmented } from "antd";
import {
    AppstoreOutlined,
    ArrowLeftOutlined,
    BarsOutlined,
    EyeInvisibleOutlined,
    EyeOutlined,
    ReloadOutlined
} from "@ant-design/icons";
import React from "react";

export default function FileToolbar({ showPreview, setShowPreview }) {
    const { selectedId, setSelectedId, reloadFolders, folders, reloadFiles } = useFileManager();
    const [loadingSearch, setLoadingSearch] = React.useState(false);

    const getParentNode = React.useCallback((key, treeData) => {
        for (let node of treeData) {
            if (node.children) {
                if (node.children.some(child => child.key === key)) {
                    return node;
                }
                const parent = getParentNode(key, node.children);
                if (parent) {
                    return parent;
                }
            }
        }
        return null;
    }, []);

    function onSearch(value, _e, info) {
        setLoadingSearch(true);
        console.log(info?.source, value);
        setTimeout(() => setLoadingSearch(false), 3000);
    }

    function togglePreview() {
        setShowPreview(!showPreview);
    }

    return <Flex
        style={{ width: "100%", paddingTop: "10px", paddingBottom: "10px" }}
        justify="space-between"
        gap="small">
        <Button
            style={{ paddingLeft: "8px", paddingRight: "8px"}}
            icon={<ArrowLeftOutlined/>}
            disabled={selectedId === '/'}
            onClick={() => {
                const parent = getParentNode(selectedId, folders);
                if (parent) {
                    setSelectedId(parent.key);
                }
            }}
        />
        <Button
            style={{ paddingLeft: "8px", paddingRight: "8px"}}
            icon={<ReloadOutlined/>}
            onClick={() => {
                reloadFolders();
                reloadFiles();
            }}
        />
        <Input.Search
            placeholder="Search files and folders"
            allowClear onSearch={onSearch}
            loading={loadingSearch}
        />
        <Button
            style={{ paddingLeft: "8px", paddingRight: "8px"}}
            icon={showPreview ? <EyeOutlined/> : <EyeInvisibleOutlined/>}
            onClick={togglePreview}
        />
        <Segmented options={[
            { value: 'List', icon: <BarsOutlined/> },
            { value: 'Kanban', icon: <AppstoreOutlined/> },
        ]}/>
    </Flex>;

}