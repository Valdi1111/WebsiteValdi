import {Link} from "react-router-dom";
import React from 'react';

export default function ShelvesListItem({ id, path, name, books, active }) {
    function isActive() {
        if (active) {
            return "active";
        }
        return "";
    }

    return (
        <li data-id={id} title={name} className={`list-group-item p-0 ${isActive()}`}>
            <Link className="d-flex flex-row justify-content-between align-items-start py-2 px-3 text-body"
                  to={`/library/shelves/${id}`}>
                <div className="min-width-0">
                    <p className="fw-bold mb-0">{name}</p>
                    <p className="small mb-0 text-truncate">Folder: {path}/</p>
                </div>
                <span className="badge bg-primary rounded-pill">{books}</span>
            </Link>
        </li>
    );
}
