import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { CheckCircleFilled } from "@ant-design/icons";
import { formatPercent } from "@CoreBundle/utils";
import { App } from "antd";
import React from "react";

export default function ItemProgress({ id, page, total, setRead }) {
    const { message } = App.useApp();
    const api = useBackendApi();

    function onMarkRead() {
        message.open({
            key: 'book-read-toggle-loader',
            type: 'loading',
            content: 'Marking book as read...',
            duration: 0,
        });
        api.books.markRead(id).then(res => {
            setRead(true);
            message.open({
                key: 'book-read-toggle-loader',
                type: 'success',
                content: 'Book marked as read',
                duration: 2.5,
            });
        });
    }

    function onMarkUnread() {
        message.open({
            key: 'book-read-toggle-loader',
            type: 'loading',
            content: 'Marking book as unread...',
            duration: 0,
        });
        api.books.markUnread(id).then(res => {
            setRead(false);
            message.open({
                key: 'book-read-toggle-loader',
                type: 'success',
                content: 'Book marked as unread',
                duration: 2.5,
            });
        });
    }

    if (page === -1) {
        return <CheckCircleFilled className="text-success" onClick={() => onMarkUnread()}/>;
    }
    return <span className="text-secondary" onClick={() => onMarkRead()}>{formatPercent(page, total)}</span>;
}
