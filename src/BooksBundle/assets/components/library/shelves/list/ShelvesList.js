import LoadingComponent from "@BooksBundle/components/LoadingComponent";
import ShelvesListButtons from "./buttons/ShelvesListButtons";
import ShelvesListItem from "./ShelvesListItem";
import React from 'react';

export default function ShelvesList({loading, shelves, shelf}) {
    function isShelfActive(s) {
        return shelf && s.id === shelf.id;
    }

    return (
        <div className={`d-md-flex flex-column col-12 col-md-4 col-lg-3 ${!shelf ? "d-flex" : "d-none"}`}>
            <div className="flex-grow-1 position-relative">
                {loading ? <LoadingComponent/> :
                    <ul className="position-absolute h-100 w-100 overflow-y-scroll list-group list-group-flush">
                        {shelves.map(s =>
                            <ShelvesListItem key={s.id} id={s.id} path={s.path} name={s.name} books={s._count} active={isShelfActive(s)}/>
                        )}
                    </ul>
                }
            </div>
            <div className="d-flex flex-row border-top p-2">
                <ShelvesListButtons shelf={shelf}/>
            </div>
        </div>
    );
}
