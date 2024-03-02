import { markRead, markUnread } from "@BooksBundle/api/book";
import React from "react";

/**
 * Dropdown menu - mark as read/unread
 * @param {int} id
 * @param {int} page
 * @param {function} setRead
 * @returns {JSX.Element}
 * @constructor
 */
export default function ItemReadToggle({ id, page, setRead }) {
    const unread = () => markUnread(id).then(res => setRead(false));
    const read = () => markRead(id).then(res => setRead(true));
    if (page === -1) {
        return (
            <li className="cursor-pointer">
                <span className="dropdown-item" onClick={unread}>Mark as Unread</span>
            </li>
        );
    }
    return (
        <li className="cursor-pointer">
            <span className="dropdown-item" onClick={read}>Mark as Read</span>
        </li>
    );
}
