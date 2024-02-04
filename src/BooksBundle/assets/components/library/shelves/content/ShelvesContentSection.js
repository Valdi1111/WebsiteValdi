import LibraryItem from "../../item/LibraryItem";
import React from 'react';

export default function ShelvesContentSection({ uid, shelf, books }) {
    const header = `section-${uid}-header`;
    const body = `section-${uid}-body`;

    return (
        <div className="accordion-item">
            <h2 id={header} className="accordion-header">
                <button className="accordion-button" type="button" data-bs-toggle="collapse"
                        data-bs-target={`#${body}`} aria-expanded={true} aria-controls={body}>
                    {shelf}
                </button>
            </h2>
            <div id={body} className="accordion-collapse collapse show" aria-labelledby={header}>
                <div className="accordion-body row mx-0 p-0">
                    {books.map(b => <LibraryItem key={b.id} book={b}/>)}
                </div>
            </div>
        </div>
    );
}
