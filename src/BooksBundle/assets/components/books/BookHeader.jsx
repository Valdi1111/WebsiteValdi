import BookContents from "@BooksBundle/components/books/contents/BookContents";
import BookTitle from "@BooksBundle/components/books/displays/BookTitle";
import BookSettings from "@BooksBundle/components/books/settings/BookSettings";
import { Flex, theme as antdTheme } from "antd";
import React from "react";

export default function BookHeader() {
    const { token: { colorBgContainer } } = antdTheme.useToken();

    return <Flex
        style={{ width: "100%", padding: "10px", alignItems: 'center', background: colorBgContainer }}
        justify="space-between"
        gap="small">
        <BookContents/>
        <BookTitle/>
        <BookSettings/>
    </Flex>;

}
