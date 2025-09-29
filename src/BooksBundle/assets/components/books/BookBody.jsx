import SpinComponent from "@CoreBundle/components/SpinComponent";
import React from "react";

export default function BookBody({ loaded }) {

    return <div className="position-relative flex-grow-1">
        <div id="book-view" className="position-absolute w-100 h-100 d-flex justify-content-center"/>
        <SpinComponent loading={!loaded} size="large"/>
    </div>;

}
