import ItemCover from "./ItemCover";
import ItemProgress from "./ItemProgress";
import ItemGoToShelf from "./ItemGoToShelf";
import ItemAbout from "./ItemAbout";
import ItemReadToggle from "./ItemReadToggle";
import ItemInvalidate from "./ItemInvalidate";
import ItemRemove from "./ItemRemove";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faEllipsis } from "@fortawesome/free-solid-svg-icons";
import React from "react";

/**
 * @typedef {{id: int, url: string, shelf_id: int, book_cache: {pages: int, cover: string}, book_metadata: {title: string, creator: string}, book_progress: {position: ?string, page: int}}} BookProps
 */

/**
 * @param {{hide_shelf: boolean|undefined, book: BookProps}} props
 * @returns {JSX.Element}
 * @constructor
 */
export default function LibraryItem(props) {
    const hide_shelf = props.hide_shelf | false;
    const { id, shelf_id, url } = props.book;
    const { cover, pages } = props.book.book_cache;
    const { title, creator } = props.book.book_metadata;
    const [page, setPage] = React.useState(props.book.book_progress.page);

    function setRead(val) {
        setPage(val ? -1 : 0);
    }

    return (
        <div className="col-auto p-2">
            <div className="d-flex flex-column" style={{ width: "150px" }}>
                <ItemCover id={id} cover={cover} title={title} creator={creator}/>
                <div className="d-flex flex-row justify-content-between align-items-center mt-1">
                    <ItemProgress page={page} total={pages}/>
                    <div className="dropdown">
                        <button type="button" id="book-other" className="btn btn-outline-secondary px-2 py-0"
                                data-bs-toggle="dropdown" aria-expanded={false}>
                            <FontAwesomeIcon icon={faEllipsis} width={16} height={16}/>
                        </button>
                        <ul className="dropdown-menu dropdown-menu-end min-width-0" aria-labelledby="book-other">
                            <ItemGoToShelf shelf_id={shelf_id} hide_shelf={hide_shelf}/>
                            <ItemAbout id={id} url={url} cover={cover}/>
                            <ItemReadToggle id={id} page={page} setRead={setRead}/>
                            <ItemInvalidate id={id} url={url} title={title}/>
                            <ItemRemove id={id} title={title}/>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    );
}
