import { useBook } from "@BooksBundle/components/books/BookContext";
import { ConfigProvider, theme as antdTheme, Tree } from "antd";
import React from "react";

export default function ContentToc() {
    const { token: { colorBgElevated } } = antdTheme.useToken();
    const { navigation, navigateTo, chapter, setContentsDrawerOpen } = useBook();

    /**
     * Navigate to chapter, ignore if href is null (section with chapters)
     */
    const onSelect = React.useCallback((selectedKeys, e) => {
        if (!e.node.href) {
            return;
        }
        navigateTo(e.node.href);
        setContentsDrawerOpen(false);
    }, [navigateTo]);

    const chapters = React.useMemo(() => {
        function transformChapters(c) {
            return c.map(i => ({ ...i, selectable: !!i.href, subitems: transformChapters(i.subitems) }))
        }
        return transformChapters(navigation);
    }, [navigation]);

    return <ConfigProvider
        theme={{
            components: {
                Tree: {
                    titleHeight: 32,
                },
            },
        }}
    >
        <Tree
            showLine={true}
            selectedKeys={[chapter?.id]}
            onSelect={onSelect}
            treeData={chapters}
            fieldNames={{ title: 'label', key: 'id' }}
            defaultExpandAll={true}
            blockNode
            styles={{
                root: {
                    backgroundColor: colorBgElevated,
                    paddingRight: '16px',
                },
            }}
        />
    </ConfigProvider>;

}
