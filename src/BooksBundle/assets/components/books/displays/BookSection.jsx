import { useBook } from "@BooksBundle/components/books/BookContext";
import React from "react";

export default function BookSection() {
    const { section } = useBook();

    if (section === null) {
        return <p id="book-section" className="col mb-0 text-center text-truncate px-2"></p>;
    }

    return <p id="book-section" className="col mb-0 text-center text-truncate px-2">
        {section.current + 1} of {section.total}
    </p>;
}
