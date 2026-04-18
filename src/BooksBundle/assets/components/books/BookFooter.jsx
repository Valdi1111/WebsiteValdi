import BookLocation from "@BooksBundle/components/books/displays/BookLocation";
import BookChapter from "@BooksBundle/components/books/displays/BookChapter";
import { useBook } from "@BooksBundle/components/books/BookContext";
import { LeftOutlined, RightOutlined } from "@ant-design/icons";
import { Button, Layout, theme as antdTheme } from "antd";
import React from "react";
import "./BookFooter.css";

export default function BookFooter() {
    const { token: { colorBgContainer } } = antdTheme.useToken();
    const { prev, next } = useBook();

    return <Layout.Footer id="book-footer" style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 8,
        padding: 10,
        height: 'auto',
        background: colorBgContainer,
    }}>
        <Button color="default" variant="filled" icon={<LeftOutlined/>} onClick={prev} style={{ flexShrink: 0 }}/>
        <div id="book-footer-left-text" style={{ flex: 1, minWidth: 0, textAlign: 'center' }}>
            <BookLocation/>
        </div>
        <div id="book-footer-right-text" style={{ flex: 1, minWidth: 0, textAlign: 'center' }}>
            <BookChapter/>
        </div>
        <Button color="default" variant="filled" icon={<RightOutlined/>} onClick={next} style={{ flexShrink: 0 }}/>
    </Layout.Footer>;

}
