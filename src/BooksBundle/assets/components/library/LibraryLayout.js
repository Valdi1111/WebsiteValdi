import HeaderNav from "@CoreBundle/components/HeaderNav";
import HeaderNavItem from "@CoreBundle/components/HeaderNavItem";
import BookAddModal from "./modals/add/BookAddModal";
import BookInfoModal from "./modals/BookInfoModal";
import BookRecreateModal from "./modals/BookRecreateModal";
import BookDeleteModal from "./modals/BookDeleteModal";
import React from "react";

export default function LibraryLayout({ children }) {

    return (
        <div className="d-flex flex-column min-vh-100">
            <BookAddModal/>
            <BookInfoModal/>
            <BookRecreateModal/>
            <BookDeleteModal/>
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
    )
}
