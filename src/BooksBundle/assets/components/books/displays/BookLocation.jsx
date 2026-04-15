import { useBook } from "@BooksBundle/components/books/BookContext";
import React from "react";

export default function BookLocation() {
    const { location } = useBook();

    if (location === null) {
        return <p id="book-location" className="col mb-0 text-center text-truncate px-2"></p>;
    }

    return <p id="book-location" className="col mb-0 text-center text-truncate px-2">
        {location.current + 1} of {location.total}
    </p>;
}
