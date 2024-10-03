import React from "react";

/**
 * Dropdown menu - about
 * @param id
 * @returns {JSX.Element}
 * @constructor
 */
export default function ItemAbout({ id }) {
    return (
        <li className="cursor-pointer">
            <span className="dropdown-item" data-bs-toggle="modal" data-bs-target="#book-info-modal" data-bs-id={id}>
                About this book
            </span>
        </li>
    );
}
