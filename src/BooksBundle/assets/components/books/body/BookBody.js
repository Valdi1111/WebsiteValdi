import BookLoadingScreen from "./BookLoadingScreen";
import React from "react";

export default function BookBody({ loaded }) {
    return (
        <div className="position-relative flex-grow-1">
            <div id="book-view" className="position-absolute w-100 h-100 d-flex justify-content-center"/>
            <BookLoadingScreen loaded={loaded}/>
        </div>
    );
}
