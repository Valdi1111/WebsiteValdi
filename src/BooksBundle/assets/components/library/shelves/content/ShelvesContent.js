import ShelvesContentSections from "./ShelvesContentSections";
import React from 'react';

export default function ShelvesContent({ content }) {
    return (
        <div className="d-md-block col-12 col-md-8 col-lg-9 position-relative">
            <div className="position-absolute h-100 w-100 overflow-y-scroll accordion accordion-flush">
                <ShelvesContentSections content={content}/>
            </div>
        </div>
    );
}
