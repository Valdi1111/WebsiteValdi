import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faEllipsis } from "@fortawesome/free-solid-svg-icons";
import React from "react";

export default function LibraryItemAdder({ hasMore, loadMore }) {

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
