import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCheckCircle } from "@fortawesome/free-solid-svg-icons";
import { formatReadPercent } from "../../../api/book";
import React from "react";

export default function ItemProgress({ page, total }) {
    if (page === -1) {
        return <FontAwesomeIcon icon={faCheckCircle} width={16} height={16} className="text-success"/>
    }
    return <span className="text-secondary">{formatReadPercent(page, total)}</span>;
}
