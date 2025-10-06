import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { CheckCircleFilled } from "@ant-design/icons";
import { formatPercent } from "@CoreBundle/format-utils";
import React from "react";

export default function ItemProgress({ id, page, total, setRead }) {
    const api = useBackendApi();

    function onMarkRead() {
        api
            .withLoadingMessage({
                key: 'book-read-toggle-loader',
                loadingContent: 'Marking book as read...',
                successContent: 'Book marked as read',
            })
            .books()
            .markRead(id)
            .then(res => {
                setRead(true);
            });
    }

    function onMarkUnread() {
        api
            .withLoadingMessage({
                key: 'book-read-toggle-loader',
                loadingContent: 'Marking book as unread...',
                successContent: 'Book marked as unread',
            })
            .books()
            .markUnread(id)
            .then(res => {
                setRead(false);
            });
    }

    if (page === -1) {
        return <CheckCircleFilled className="text-success" onClick={() => onMarkUnread()}/>;
    }
    return <span className="text-secondary" onClick={() => onMarkRead()}>{formatPercent(page, total)}</span>;
}
