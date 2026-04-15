import { useBook } from "@BooksBundle/components/books/BookContext";
import React from "react";

export default function BookTitle() {
    const { title } = useBook();

    if (title === null) {
        return <p id="book-title" className="flex-grow-1 mb-0 text-center text-truncate px-2" title=""></p>;
    }

    return <p id="book-title" className="flex-grow-1 mb-0 text-center text-truncate px-2" title={title}>
        {title}
    </p>;
}
