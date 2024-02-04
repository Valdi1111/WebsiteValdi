import React from "react";

/**
 * Dropdown menu - about
 * @param id
 * @param url
 * @param cover
 * @returns {JSX.Element}
 * @constructor
 */
export default function ItemAbout({ id, url, cover }) {
    return (
        <li className="cursor-pointer">
            <span className="dropdown-item" data-bs-toggle="modal" data-bs-target="#book-info-modal" data-bs-id={id}
                  data-bs-url={url}>
                About this book
            </span>
        </li>
    );
}
