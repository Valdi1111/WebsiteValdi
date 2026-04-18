import { useBook } from "@BooksBundle/components/books/BookContext";
import { Typography } from "antd";
import React from "react";

export default function BookChapter() {
    const { chapter } = useBook();

    return <Typography.Text id="book-chapter" ellipsis={{ tooltip: true }}>
        {chapter?.label}
    </Typography.Text>;
}
