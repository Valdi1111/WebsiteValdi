import React from "react";

export default function LoadingComponent() {
    return (
        <div className="h-100 w-100 d-flex justify-content-center align-items-center">
            <div className="spinner-border" role="status">
                <span className="visually-hidden">Loading...</span>
            </div>
        </div>
    );
}
