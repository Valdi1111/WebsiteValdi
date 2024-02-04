import {Link} from "react-router-dom";
import React from "react";

/**
 * Dropdown menu - go to shelf
 * @param shelf_id
 * @param hide_shelf
 * @returns {JSX.Element}
 * @constructor
 */
export default function ItemGoToShelf({ shelf_id, hide_shelf }) {
    if (hide_shelf || !shelf_id) {
        return <></>;
    }
    return (
        <li>
            <Link to={`/library/shelves/${shelf_id}`} className="dropdown-item">Go to shelf</Link>
        </li>
    );
}
