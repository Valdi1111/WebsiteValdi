import LoadingComponent from "../../../LoadingComponent";
import ModalSection from "./ModalSection";
import React from "react";

export default function ModalSections({ content, update }) {
    if (Object.keys(content).length === 0) {
        return (
            <div className="d-flex justify-content-center align-items-center" style={{ minHeight: '250px' }}>
                <LoadingComponent/>
            </div>
        );
    }

    let e = [];
    let num = 0;
    for (let key in content) {
        let val = content[key];
        e = [...e, <ModalSection key={key} uid={num} shelf={key} books={val} update={update}/>];
        num++;
    }
    return e;
}
