import { EditOutlined, MenuOutlined, SearchOutlined, TagsOutlined, UnorderedListOutlined } from "@ant-design/icons";
import ContentToc from "@BooksBundle/components/books/contents/ContentToc";
import ContentNotes from "@BooksBundle/components/books/contents/ContentNotes";
import ContentBookmarks from "@BooksBundle/components/books/contents/ContentBookmarks";
import ContentSearch from "@BooksBundle/components/books/contents/ContentSearch";
import { useBook } from "@BooksBundle/components/books/BookContext";
import { Button, Drawer, Tabs } from "antd";
import React from "react";
import "./BookContents.css";

const items = [
    {
        key: 'toc',
        label: 'TOC',
        icon: <UnorderedListOutlined/>,
        children: <ContentToc/>,
    },
    {
        key: 'notes',
        label: 'Notes',
        icon: <EditOutlined/>,
        children: <ContentNotes/>,
    },
    {
        key: 'bookmarks',
        label: 'Bookmarks',
        icon: <TagsOutlined/>,
        children: <ContentBookmarks/>,
    },
    {
        key: 'search',
        label: 'Search',
        icon: <SearchOutlined/>,
        children: <ContentSearch/>,
    },
];

export default function BookContents() {
    const { contentsDrawerOpen, setContentsDrawerOpen } = useBook();

    return <>
        <Button
            style={{ flexShrink: 0 }}
            color="default"
            variant="filled"
            icon={<MenuOutlined/>}
            onClick={() => setContentsDrawerOpen(current => !current)}
        />
        <Drawer
            title="Contents"
            placement="left"
            onClose={() => setContentsDrawerOpen(false)}
            open={contentsDrawerOpen}
            key="book-contents-drawer"
            styles={{
                body: {
                    height: '100%',
                    padding: 0,
                },
            }}
        >
            <Tabs
                id="#book-contents-drawer-tabs"
                defaultActiveKey="toc"
                items={items}
                centered={true}
                styles={{
                    root: {
                        flex: 1,
                        minHeight: 0,
                    },
                    item: {
                        margin: '0 8px',
                    },
                }}
            />
        </Drawer>
    </>;

}
