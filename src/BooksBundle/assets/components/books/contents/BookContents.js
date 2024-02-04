import ContentsHeader from "./ContentsHeader";
import ContentsBody from "./ContentsBody";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faBars } from "@fortawesome/free-solid-svg-icons";
import React from "react";

export const TOC = 'toc';
export const ANNOTATIONS = 'annotations';
export const BOOKMARKS = 'bookmarks';
export const SEARCH = 'search';

export default function BookContents({ chapter, navigation, navigateTo, search }) {
    const [content, setContent] = React.useState(TOC);
    const dropdown = React.useRef();

    function close() {
        dropdown.current.click();
    }

    return (
        <div className="dropdown">
            <button className="btn btn-icon btn-outline-secondary" type="button" id="ctn-dropdown"
                    data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded={false} ref={dropdown}>
                <FontAwesomeIcon icon={faBars} width={16} height={16}/>
            </button>
            <div className="dropdown-menu" aria-labelledby="ctn-dropdown" style={{ width: "300px" }}>
                <ContentsHeader setContent={setContent}/>
                <hr className="text-secondary my-2"/>
                <ContentsBody content={content} close={close} search={search} chapter={chapter} navigation={navigation}
                              navigateTo={navigateTo}/>
            </div>
        </div>
    );
}
