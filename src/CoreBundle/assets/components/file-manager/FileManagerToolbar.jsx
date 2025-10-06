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

export default function FileManagerToolbar({ showPreview, setShowPreview }) {
    const [loadingSearch, setLoadingSearch] = React.useState(false);

    const { folders, reloadFolders, selectedFolder, setSelectedFolder, reloadFiles, setSelectedFile, setClipboard } = useFileManager();

    const getParentNode = React.useCallback((selected, treeData) => {
        for (let node of treeData) {
            if (node.children) {
                if (node.children.some(child => child.id === selected.id)) {
                    return node;
                }
                const parent = getParentNode(selected, node.children);
                if (parent) {
                    return parent;
                }
            }
        }
        return null;
    }, []);

    function onSearch(value, e, info) {
        // TODO implement file search
        setLoadingSearch(true);
        setTimeout(() => setLoadingSearch(false), 3000);
    }

    function togglePreview() {
        setShowPreview(!showPreview);
    }

    return <Flex
        style={{ width: "100%", padding: "10px" }}
        justify="space-between"
        gap="small">
        <Button
            style={{ paddingLeft: "8px", paddingRight: "8px"}}
            icon={<ArrowLeftOutlined/>}
            disabled={selectedFolder?.id === "/"}
            onClick={() => {
                const parent = getParentNode(selectedFolder, folders);
                if (parent) {
                    setSelectedFolder(parent);
                }
            }}
        />
        <Button
            style={{ paddingLeft: "8px", paddingRight: "8px"}}
            icon={<ReloadOutlined/>}
            onClick={() => {
                setClipboard(null);
                reloadFolders().then(t => setSelectedFolder(t[0]));
                reloadFiles().then(() => setSelectedFile(null));
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