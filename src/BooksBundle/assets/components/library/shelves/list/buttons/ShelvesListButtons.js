import ShelvesListAddButton from "./ShelvesListAddButton";
import ShelvesListEditButton from "./ShelvesListEditButton";
import ShelvesListDeleteButton from "./ShelvesListDeleteButton";
import React from 'react';

export default function ShelvesListButtons({ shelf }) {

    if (!shelf) {
        return <ShelvesListAddButton/>;
    }

    return (
        <>
            <ShelvesListAddButton/>
            <ShelvesListEditButton id={shelf.id} name={shelf.name} path={shelf.path}/>
            <ShelvesListDeleteButton id={shelf.id} name={shelf.name}/>
        </>
    );
}
