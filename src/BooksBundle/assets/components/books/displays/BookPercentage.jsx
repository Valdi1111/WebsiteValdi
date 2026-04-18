import { useBook } from "@BooksBundle/components/books/BookContext";
import { Typography } from "antd";
import React from "react";

export default function BookPercentage() {
    const { percentage } = useBook();

    if (percentage === null) {
        return <Typography.Text id="book-percentage"/>;
    }

    return <Typography.Text id="book-percentage" ellipsis>
        {Math.round(percentage * 100)}%
    </Typography.Text>;
}
