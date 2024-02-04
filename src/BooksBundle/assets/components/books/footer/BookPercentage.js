import React from "react";

export default function BookPercentage({ percentage }) {

    if (percentage === null) {
        return (
            <p id="book-percentage" className="col mb-0 text-center text-truncate px-2"></p>
        );
    }

    return (
        <p id="book-percentage" className="col mb-0 text-center text-truncate px-2">
            {Math.round(percentage * 100)}%
        </p>
    );
}
