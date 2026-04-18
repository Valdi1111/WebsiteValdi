import { useBook } from "@BooksBundle/components/books/BookContext";
import { Typography } from "antd";
import React from "react";

export default function BookTitle() {
    const { title } = useBook();

    return <Typography.Text id="book-title" ellipsis={{ tooltip: true }}>
        {title}
    </Typography.Text>;
}
