import LibraryItemAdder from "@BooksBundle/components/library/item/LibraryItemAdder";
import LibraryItem from "@BooksBundle/components/library/item/LibraryItem";
import SpinComponent from "@CoreBundle/components/SpinComponent";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { BOOKS_PER_PAGE } from "@BooksBundle/constants";
import { Helmet } from "react-helmet";
import { Col, Row } from "antd";
import React from "react";

export default function LibraryNotInShelves() {
    const [data, setData] = React.useState([]);
    const [hasMore, setHasMore] = React.useState(false);
    const [loading, setLoading] = React.useState(true);
    const [loadingMore, setLoadingMore] = React.useState(false);
    const page = React.useRef(1);

    /** @type {MutableRefObject<EventSource>}*/
    const ws = React.useRef(null);
    const api = useBackendApi();

    React.useEffect(() => {
        // Append the topic(s) to subscribe as query parameter
        const hub = new URL(MERCURE_HUB_URL, window.origin);
        hub.searchParams.append('topic', `https://books.valdi.ovh/library/not-in-shelves`);
        // Subscribe to updates
        ws.current = new EventSource(hub, { withCredentials: true });
        ws.current.addEventListener('message', handleWebsocket);
        // Close connection on unmount
        return () => ws.current?.close();
    }, []);

    React.useEffect(() => {
        refreshBooks();
    }, []);

    const refreshBooks = React.useCallback(() => {
        setLoading(true);
        return api
            .withErrorHandling()
            .books()
            .getNotInShelf(BOOKS_PER_PAGE * page.current, 0)
            .then(res => {
                setData(res.data.books);
                setHasMore(res.data.books_count > BOOKS_PER_PAGE * page.current);
                setLoading(false);
            });
    }, []);

    /**
     * Will be called every time an update is published by the server
     * @param event
     */
    const handleWebsocket = React.useCallback((event) => {
        const json = JSON.parse(event.data);
        if (json.action === 'book:add') {
            refreshBooks();
        }
        if (json.action === 'book:recreate') {
            refreshBooks();
        }
        if (json.action === 'book:remove') {
            if (data.find(b => b.id === parseInt(json.book.id))) {
                refreshBooks();
            }
        }
    }, [data]);

    function loadMore() {
        if (loadingMore) {
            return;
        }
        setLoadingMore(true);
        api
            .withErrorHandling()
            .books()
            .getNotInShelf(BOOKS_PER_PAGE, BOOKS_PER_PAGE * page.current)
            .then(res => {
                page.current++;
                setData([...data, ...res.data.books]);
                setHasMore(res.data.books_count > BOOKS_PER_PAGE * page.current);
                setLoadingMore(false);
            });
    }

    return <>
        <Helmet>
            <title>Not in shelves</title>
        </Helmet>
        <div style={{ overflowY: 'scroll', width: '100%' }}>
            <SpinComponent loading={loading} size="large">
                <Row style={{ padding: '8px', marginRight: 0, marginLeft: 0 }}
                     gutter={[8, 8]}>
                    {data.map(book =>
                        <Col key={book.id} style={{ width: '165px' }}>
                            <LibraryItem key={book.id} book={book}/>
                        </Col>
                    )}
                    <Col style={{ width: '165px' }}>
                        <LibraryItemAdder hasMore={hasMore} loadMore={loadMore} loadingMore={loadingMore}/>
                    </Col>
                </Row>
            </SpinComponent>
        </div>
    </>;

}