import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faHome} from "@fortawesome/free-solid-svg-icons";
import BreadcrumbItem from "./BreadcrumbItem";
import {Link} from "react-router-dom";
import React from "react";

export default function Breadcrumb({home, items}) {

    return (
        <nav aria-label="breadcrumb">
            <ol className="breadcrumb breadcrumb-chevron p-3 bg-body-tertiary rounded-3">
                <li className="breadcrumb-item">
                    <Link className="link-body-emphasis" to={home}>
                        <FontAwesomeIcon icon={faHome}/>
                        <span className="visually-hidden">Home</span>
                    </Link>
                </li>
                {items.map(item => <BreadcrumbItem key={item.name} path={item.path} name={item.name}/>)}
            </ol>
        </nav>
    );

}