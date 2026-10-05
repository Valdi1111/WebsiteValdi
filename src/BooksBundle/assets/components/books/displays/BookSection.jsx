import { useBook } from "@BooksBundle/components/books/BookContext";
import { Typography } from "antd";
import React from "react";

export default function BookSection() {
    const { section } = useBook();

    if (!section || section.total == null) {
        return <Typography.Text id="book-section" />;
    }

    return (
        <Typography.Text id="book-section" ellipsis>
            {(section.current ?? 0) + 1} of {section.total}
        </Typography.Text>
    );
}
