import {LibraryUpdateContext} from "../Contexts";
import HeaderNav from "@CoreBundle/components/HeaderNav";
import HeaderNavItem from "@CoreBundle/components/HeaderNavItem";
import BookAddModal from "./modals/add/BookAddModal";
import BookInfoModal from "./modals/BookInfoModal";
import BookRecreateModal from "./modals/BookRecreateModal";
import BookDeleteModal from "./modals/BookDeleteModal";
import React from "react";

export default function LibraryLayout({ children }) {
    const [update, setUpdate] = React.useState({});

    function onBookAdd(data) {
        setUpdate({ type: 'add', items: data });
    }

    function onBookRecreate(data) {
        setUpdate({ type: 'recreate', id: data.id, shelf_id: data.shelf_id });
    }

    function onBookDelete(id) {
        setUpdate({ type: 'delete', id });
    }

    return (
        <LibraryUpdateContext.Provider value={[update, setUpdate]}>
            <div className="d-flex flex-column min-vh-100">
                <BookAddModal update={onBookAdd}/>
                <BookInfoModal/>
                <BookRecreateModal update={onBookRecreate}/>
                <BookDeleteModal update={onBookDelete}/>
                <HeaderNav
                    navbar={<>
                        <HeaderNavItem path="/library/all" name="All books"/>
                        <HeaderNavItem path="/library/shelves" name="Shelves"/>
                        <HeaderNavItem path="/library/not-in-shelves" name="Not in shelves"/>
                    </>}
                    dropdown={<>
                        <li><span className="dropdown-item cursor-pointer" data-bs-toggle="modal"
                                  data-bs-target="#book-add-modal">Add</span></li>
                        <li><span className="dropdown-item cursor-pointer" data-bs-toggle="modal"
                                  data-bs-target="#theme-change-modal">Theme</span></li>
                    </>}
                />
                {children}
            </div>
        </LibraryUpdateContext.Provider>
    )
}
