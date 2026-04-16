import BookLocation from "@BooksBundle/components/books/displays/BookLocation";
import BookChapter from "@BooksBundle/components/books/displays/BookChapter";
import { useBook } from "@BooksBundle/components/books/BookContext";
import { LeftOutlined, RightOutlined } from "@ant-design/icons";
import { Button, Flex, theme as antdTheme } from "antd";
import React from "react";

export default function BookFooter() {
    const { token: { colorBgContainer } } = antdTheme.useToken();
    const { prev, next } = useBook();

    return <Flex
        style={{ width: "100%", padding: "10px", alignItems: 'center', background: colorBgContainer }}
        justify="space-between"
        gap="small">
        <Button color="default" variant="filled" icon={<LeftOutlined/>} onClick={prev}/>
        <BookLocation/>
        <BookChapter/>
        <Button color="default" variant="filled" icon={<RightOutlined/>} onClick={next}/>
    </Flex>;

}
