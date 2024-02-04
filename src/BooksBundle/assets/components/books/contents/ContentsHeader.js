import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faListSquares, faPencil, faBookmark, faMagnifyingGlass } from "@fortawesome/free-solid-svg-icons";
import React from "react";

export default function ContentsHeader({ setContent }) {
    function change(e) {
        setContent(e.target.value);
    }

    return (
        <div className="btn-group w-100 px-2" role="group" aria-label="Contents button group">
            <input type="radio" className="btn-check" name="cnt-radio" id="cnt-toc" autoComplete="off"
                   defaultChecked={true} onChange={change} value="toc"/>
            <label className="btn btn-icon btn-outline-secondary" htmlFor="cnt-toc">
                <FontAwesomeIcon icon={faListSquares} width={16} height={16}/>
            </label>
            <input type="radio" className="btn-check" name="cnt-radio" id="cnt-annotations" autoComplete="off"
                   onChange={change} value="annotations"/>
            <label className="btn btn-icon btn-outline-secondary" htmlFor="cnt-annotations">
                <FontAwesomeIcon icon={faPencil} width={16} height={16}/>
            </label>
            <input type="radio" className="btn-check" name="cnt-radio" id="cnt-bookmarks" autoComplete="off"
                   onChange={change} value="bookmarks"/>
            <label className="btn btn-icon btn-outline-secondary" htmlFor="cnt-bookmarks">
                <FontAwesomeIcon icon={faBookmark} width={16} height={16}/>
            </label>
            <input type="radio" className="btn-check" name="cnt-radio" id="cnt-search" autoComplete="off"
                   onChange={change} value="search"/>
            <label className="btn btn-icon btn-outline-secondary" htmlFor="cnt-search">
                <FontAwesomeIcon icon={faMagnifyingGlass} width={16} height={16}/>
            </label>
        </div>
    );
}
