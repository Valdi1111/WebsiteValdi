import { useBook } from "@BooksBundle/components/books/BookContext";
import { Typography } from "antd";
import React from "react";

export default function BookLocation() {
    const { location } = useBook();

    if (location === null) {
        return <Typography.Text id="book-location"/>;
    }

    return <Typography.Text id="book-location" ellipsis>
        {location.current + 1} of {location.total}
    </Typography.Text>;
}
