import React from "react";

/**
 * Dropdown menu - remove
 * @param id
 * @param title
 * @returns {JSX.Element}
 * @constructor
 */
export default function ItemRemove({ id, title }) {
    return (
        <li className="cursor-pointer">
            <span className="dropdown-item" data-bs-toggle="modal" data-bs-target="#book-delete-modal" data-bs-id={id}
                  data-bs-title={title}>
                Remove
            </span>
        </li>
    );
}
