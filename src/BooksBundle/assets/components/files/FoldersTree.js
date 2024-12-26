import { getFolders } from "@BooksBundle/api/files";
import Search from "antd/es/input/Search";
import { Tree } from "antd";
import React from "react";
import Highlighter from "react-highlight-words";

export default function FoldersTree() {
    const [treeData, setTreeData] = React.useState([{
        key: "/",
        title: "Root",
        children: [],
        isLeaf: false,
    }]);
    const [expandedKeys, setExpandedKeys] = React.useState(["/"]);
    const [searchText, setSearchText] = React.useState("");

    async function loadData({ key }) {
        if (key !== "/") {
            return null;
        }
        return getFolders().then(
            res => {
                setTreeData([{
                    key: "/",
                    title: "Root",
                    children: transformData(res.data)
                }]);
            },
            err => console.error(err)
        );
    }

    function transformData(items) {
        const res = [];
        for (const item of items) {
            const children = transformData(item.data);
            res.push({
                key: item.id,
                title: item.value,
                children: children,
                isLeaf: children.length === 0,
            });
        }
        return res.sort((a, b) => a.title.localeCompare(b.title, 'it'));
    }

    function onSelect(selectedKeys, info) {
        console.log('selected', selectedKeys, info);
    }

    function onSearch(e) {
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
        treeData.forEach(node => traverse(node));

        // Converte il Set in array prima di restituire
        setExpandedKeys(Array.from(nodesToExpand));
    }

    function titleRender({ key, title }) {
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
    }

    function filterTreeNode({ key }) {
        return searchText && expandedKeys.includes(key);
    }

    return <div>
        <Search style={{ marginBottom: 8 }} placeholder="Search folders" onChange={onSearch} allowClear/>
        <Tree
            loadData={loadData}
            treeData={treeData}
            filterTreeNode={filterTreeNode}
            onExpand={newExpandedKeys => setExpandedKeys(newExpandedKeys)}
            expandedKeys={expandedKeys}
            defaultSelectedKeys={['/']}
            onSelect={onSelect}
            titleRender={titleRender}
            showLine
        />
    </div>;

}