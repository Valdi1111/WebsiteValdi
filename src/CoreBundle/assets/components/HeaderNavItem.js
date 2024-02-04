import React from 'react';
import {Link, useLocation} from "react-router-dom";

export default function HeaderNavItem({path, name}) {
    const router = useLocation();

    if (!router.pathname.startsWith(path)) {
        return (
            <li className="nav-item">
                <Link to={path} className="nav-link text-center">{name}</Link>
            </li>
        );
    }

    return (
        <li className="nav-item">
            <Link to={path} className="nav-link text-center active" aria-current="page">{name}</Link>
        </li>
    );

}