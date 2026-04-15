import BookLocation from "@BooksBundle/components/books/displays/BookLocation";
import BookChapter from "@BooksBundle/components/books/displays/BookChapter";
import { useBook } from "@BooksBundle/components/books/BookContext";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faAngleLeft, faAngleRight } from "@fortawesome/free-solid-svg-icons";
import { Flex, theme as antdTheme } from "antd";
import React from "react";

export default function BookFooter() {
    const { token: { colorBgContainer } } = antdTheme.useToken();
    const { prev, next } = useBook();

    return <Flex
        style={{ width: "100%", padding: "10px", alignItems: 'center', background: colorBgContainer }}
        justify="space-between"
        gap="small">
        <button className="btn btn-icon btn-outline-secondary" onClick={prev}>
            <FontAwesomeIcon icon={faAngleLeft} width={16} height={16}/>
        </button>
        <BookLocation/>
        <BookChapter/>
        <button className="btn btn-icon btn-outline-secondary" onClick={next}>
            <FontAwesomeIcon icon={faAngleRight} width={16} height={16}/>
        </button>
    </Flex>;

}
