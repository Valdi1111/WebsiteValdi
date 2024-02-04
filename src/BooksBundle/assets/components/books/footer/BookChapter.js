import React from "react";

export default function BookChapter({ chapter }) {

    if (chapter === null) {
        return (
            <p id="book-chapter" className="d-none d-md-block col mb-0 text-center text-truncate px-2" title=""></p>
        );
    }

    return (
        <p id="book-chapter" className="d-none d-md-block col mb-0 text-center text-truncate px-2"
           title={chapter.label}>
            {chapter.label}
        </p>
    );
}
