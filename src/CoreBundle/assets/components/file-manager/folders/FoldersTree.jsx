import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import FoldersTreeAddNew from "@CoreBundle/components/file-manager/folders/FoldersTreeAddNew";
import { Button, Flex, Popover, Tree } from "antd";
import { FilterOutlined } from "@ant-design/icons";
import Highlighter from "react-highlight-words";
import Search from "antd/es/input/Search";
import React from "react";

export default function FoldersTree() {
    const { folders, reloadFolders, selectedId, setSelectedId, setSelectedKey } = useFileManager();
    const [expandedKeys, setExpandedKeys] = React.useState(["/"]);
    const [searchText, setSearchText] = React.useState("");

    const onSearch = React.useCallback(e => {
        const { value } = e.target;
        setSearchText(value);
        if (!value) {
            setExpandedKeys(["/"]);
            return;
        }
        const nodesToExpand = new Set();

        function traverse(node, parentKeys = []) {
            const currentKeys = [...parentKeys, node.key];

            // Aggiungi il nodo alla lista se il titolo contiene la stringa di ricerca
            if (node.title.toLowerCase().includes(value.toLowerCase())) {
                currentKeys.forEach(key => nodesToExpand.add(key));
            }

            // Continua la ricerca nei figli, se esistono
            if (node.children && node.children.length > 0) {
                node.children.forEach(child => traverse(child, currentKeys));
            }
        }

        // Inizia la traversata dall'albero radice
        folders.forEach(node => traverse(node));

        // Converte il Set in array prima di restituire
        setExpandedKeys(Array.from(nodesToExpand));
    }, [folders]);

    const filterTreeNode = React.useCallback(({ key }) => {
        return searchText && expandedKeys.includes(key);
    }, [searchText, expandedKeys]);

    const titleRender = React.useCallback(({ key, title }) => {
        if (!searchText || !expandedKeys.includes(key)) {
            return title;
        }
        return <Highlighter
            highlightStyle={{
                backgroundColor: '#ffc069',
                padding: 0,
            }}
            searchWords={[searchText]}
            autoEscape
            textToHighlight={title ? title.toString() : ''}
        />;
    }, [searchText, expandedKeys]);

    function onSelect(selectedKeys, e) {
        setSelectedKey(null);
        setSelectedId(selectedKeys[0]);
        console.debug('Selected', selectedKeys);
    }

    return <>
        <Flex gap="small" style={{ width: "100%" }}>
            <FoldersTreeAddNew/>
            <Popover placement={"left"} arrow content={
                <Search placeholder="Search folders" onChange={onSearch} allowClear/>
            }>
                <Button style={{ paddingLeft: "8px", paddingRight: "8px"}} icon={<FilterOutlined/>}/>
            </Popover>
        </Flex>
        <Tree
            loadData={reloadFolders}
            treeData={folders}
            filterTreeNode={filterTreeNode}
            onExpand={newExpandedKeys => setExpandedKeys(newExpandedKeys)}
            expandedKeys={expandedKeys}
            defaultSelectedKeys={['/']}
            selectedKeys={[selectedId]}
            onSelect={onSelect}
            titleRender={titleRender}
            showLine
        />
    </>;

}