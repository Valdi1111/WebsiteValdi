import {Link} from "react-router-dom";
import React from "react";

export default function BreadcrumbItem({path, name}) {

    if (!path) {
        return (
            <li className="breadcrumb-item active" aria-current="page">Data</li>
        );
    }

    return (
        <li className="breadcrumb-item">
            <Link className="link-body-emphasis fw-semibold text-decoration-none" to={path}>{name}</Link>
        </li>
    );

}