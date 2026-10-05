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
    }, [navigateTo, setContentsDrawerOpen]);

    const chapters = React.useMemo(() => {
        function transformChapters(c = []) {
            if (!Array.isArray(c)) {
                return [];
            }
            return c.map(i => ({
                ...i,
                key: i.id || i.href || Math.random().toString(),
                selectable: !!i.href,
                children: transformChapters(i.subitems || []),
            }));
        }
        return transformChapters(navigation);
    }, [navigation]);

    return (
        <ConfigProvider
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
                selectedKeys={chapter?.id ? [chapter.id] : []}
                onSelect={onSelect}
                treeData={chapters}
                fieldNames={{ title: 'label', key: 'key', children: 'children' }}
                defaultExpandAll={true}
                blockNode
                styles={{
                    root: {
                        backgroundColor: colorBgElevated,
                        paddingRight: 16,
                    },
                }}
            />
        </ConfigProvider>
    );
}
