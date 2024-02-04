import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faPlus } from "@fortawesome/free-solid-svg-icons";
import React from 'react';

export default function ShelvesListAddButton() {
    return (
        <button className="btn btn-success btn-icon" type="button" data-bs-toggle="modal"
                data-bs-target="#shelf-add-modal">
            <FontAwesomeIcon icon={faPlus} width={16} height={16}/>
        </button>
    );
}
