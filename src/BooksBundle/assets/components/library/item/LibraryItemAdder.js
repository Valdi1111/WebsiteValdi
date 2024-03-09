import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faEllipsis } from "@fortawesome/free-solid-svg-icons";
import LoadingComponent from "@BooksBundle/components/LoadingComponent";
import React from "react";

export default function LibraryItemAdder({ hasMore, loadMore, loadingMore }) {

    if (loadingMore) {
        return (
            <div className="col-auto p-2">
                <div className="d-flex flex-column align-items-center justify-content-center border"
                     style={{ width: "150px", height: "225px" }}>
                    <LoadingComponent/>
                </div>
            </div>
        );
    }

    if (hasMore) {
        return (
            <div className="col-auto p-2">
                <div className="d-flex flex-column align-items-center justify-content-center border"
                     style={{ width: "150px", height: "225px" }} onClick={loadMore}>
                    <FontAwesomeIcon icon={faEllipsis} width={16} height={16}/>
                </div>
            </div>
        );
    }

    return <></>;
}
