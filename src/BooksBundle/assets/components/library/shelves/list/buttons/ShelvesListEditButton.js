import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faPencil } from "@fortawesome/free-solid-svg-icons";
import React from 'react';

export default function ShelvesListEditButton({ id, name, path }) {
    return (
        <button className="btn btn-primary btn-icon ms-auto" type="button" data-bs-toggle="modal"
                data-bs-target="#shelf-edit-modal" data-bs-path={path} data-bs-name={name} data-bs-id={id}>
            <FontAwesomeIcon icon={faPencil} width={16} height={16}/>
        </button>
    );
}
