import { useNavigate } from "react-router-dom";
import React from "react";

export default function ItemCover({ id, hasCover, coverUrl, title, creator }) {
    const navigate = useNavigate();

    if (hasCover) {
        return <img style={{ width: '100%', height: '100%', objectFit: 'cover', aspectRatio: '2 / 3' }}
                    alt="Book cover" src={coverUrl} onClick={() => navigate(`/books/${id}`)}/>;
    }

    return <div onClick={() => navigate(`/books/${id}`)} style={{ width: '100%', height: '100%', aspectRatio: '2 / 3' }}
         className="d-flex flex-column justify-content-center py-3">
        <p className="overflow-hidden border-top border-bottom p-2 mb-1"
           style={{ fontWeight: 500, fontSize: '95%' }}>
            {title}
        </p>
        <span className="small align-self-end px-2">{creator}</span>
    </div>;
}
