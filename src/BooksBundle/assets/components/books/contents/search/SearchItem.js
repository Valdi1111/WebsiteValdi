import React from "react";

export default function SearchItem({ close, item, navigateTo }) {
    function onClick() {
        navigateTo(item.cfi)
    }

    if (!item.chapter) {
        return (
            <li className="dropdown-item px-2" onClick={onClick}>
                <span className="text-wrap">{item.excerpt}</span>
            </li>
        );
    }

    return (
        <li className="dropdown-item px-2" onClick={onClick}>
            <p className="text-wrap text-secondary mb-0" style={{ fontSize: "85%" }}>{item.chapter.label}</p>
            <span className="text-wrap">{item.excerpt}</span>
        </li>
    );
}
