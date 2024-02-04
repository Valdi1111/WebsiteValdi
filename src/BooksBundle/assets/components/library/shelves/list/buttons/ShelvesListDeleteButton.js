import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faTrash } from "@fortawesome/free-solid-svg-icons";
import React from 'react';

export default function ShelvesListDeleteButton({ id, name }) {
    return (
        <button className="btn btn-danger btn-icon ms-2" type="button" data-bs-toggle="modal"
                data-bs-target="#shelf-delete-modal" data-bs-name={name} data-bs-id={id}>
            <FontAwesomeIcon icon={faTrash} width={16} height={16}/>
        </button>
    );
}
