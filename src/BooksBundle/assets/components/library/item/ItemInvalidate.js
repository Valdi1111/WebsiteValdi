import React from "react";

/**
 * Dropdown menu - recreate cache
 * @param id
 * @param title
 * @param url
 * @returns {JSX.Element}
 * @constructor
 */
export default function ItemInvalidate({ id, title }) {
    return (
        <li className="cursor-pointer">
            <span className="dropdown-item" data-bs-toggle="modal" data-bs-target="#book-invalidate-modal"
                  data-bs-id={id} data-bs-title={title}>
                Recreate cache
            </span>
        </li>
    );
}
