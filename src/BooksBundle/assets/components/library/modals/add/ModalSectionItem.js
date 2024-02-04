import { CHECKBOX_NAME, PREFIX } from "./BookAddModal";
import React from "react";

export default function ModalSectionItem({ id, uid, book, update }) {
    const item = `${PREFIX}-section-${uid}-book-${id}`;

    return (
        <div className="form-check">
            <input className="form-check-input" type="checkbox" id={item} name={CHECKBOX_NAME} value={book.path}
                   onChange={update}/>
            <label className="form-check-label" htmlFor={item}>{book.file}</label>
        </div>
    );
}
