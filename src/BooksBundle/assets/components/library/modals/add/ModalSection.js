import ModalSectionItem from "./ModalSectionItem";
import { PREFIX } from "./BookAddModal";
import React from "react";

export default function ModalSection({ uid, shelf, books, update }) {
    const header = `${PREFIX}-section-${uid}-header`;
    const body = `${PREFIX}-section-${uid}-body`;

    return (
        <div className="accordion-item">
            <h2 id={header} className="accordion-header">
                <button className="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                        data-bs-target={`#${body}`} aria-expanded={false} aria-controls={body}>
                    {shelf}
                </button>
            </h2>
            <div id={body} className="accordion-collapse collapse" aria-labelledby={header}
                 data-bs-parent="#book-add-modal-accordion">
                <div className="accordion-body">
                    {books.map((book, id) =>
                        <ModalSectionItem key={id} id={id} uid={uid} book={book} update={update}/>
                    )}
                </div>
            </div>
        </div>
    );
}
