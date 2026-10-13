import { useBook } from "@BooksBundle/components/books/BookContext";
import { Typography } from "antd";
import React from "react";

export default function BookPercentage() {
    const { location } = useBook();

    if (location.fraction == null) {
        return <Typography.Text id="book-percentage" />;
    }

    return (
        <Typography.Text id="book-percentage" ellipsis>
            {Math.round(location.fraction * 100)}%
        </Typography.Text>
    );
}
