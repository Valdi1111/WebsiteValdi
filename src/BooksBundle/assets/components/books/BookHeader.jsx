import BookContents from "@BooksBundle/components/books/contents/BookContents";
import BookTitle from "@BooksBundle/components/books/displays/BookTitle";
import BookSettings from "@BooksBundle/components/books/settings/BookSettings";
import { Layout, theme as antdTheme } from "antd";
import React from "react";

export default function BookHeader() {
    const { token: { colorBgContainer, colorBorderSecondary } } = antdTheme.useToken();

    return <Layout.Header id="book-header" style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 8,
        padding: 10,
        height: 'auto',
        background: colorBgContainer,
        borderBottom: `1px solid ${colorBorderSecondary}`,
    }}>
        <BookContents/>
        <BookTitle/>
        <BookSettings/>
    </Layout.Header>;

}
