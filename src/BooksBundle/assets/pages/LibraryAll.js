import {BOOKS_PER_PAGE, getBooksAll} from "@BooksBundle/api/library";
import LibraryItemAdder from "@BooksBundle/components/library/item/LibraryItemAdder";
import LibraryItem from "@BooksBundle/components/library/item/LibraryItem";
import LoadingComponent from "@BooksBundle/components/LoadingComponent";
import {Helmet} from "react-helmet";
import React from "react";

export default function LibraryAll() {
    const [hasMore, setHasMore] = React.useState(false);
    const [loading, setLoading] = React.useState(true);
    const [loadingMore, setLoadingMore] = React.useState(false);
    const books = React.useRef([]);
    const page = React.useRef(1);
    /** @type {MutableRefObject<EventSource>}*/
    const ws = React.useRef(null);

    React.useEffect(() => {
        refreshBooks().then(() => startWebsocket());
        return stopWebsocket;
    }, []);

    function refreshBooks() {
        setLoading(true);
        return getBooksAll(BOOKS_PER_PAGE * page.current + 1, 0).then(
            res => {
                books.current = res.data.slice(0, BOOKS_PER_PAGE * page.current);
                setHasMore(res.data.length > BOOKS_PER_PAGE * page.current);
                setLoading(false);
            },
            err => console.error(err)
        );
    }

    function startWebsocket() {
        // Append the topic(s) to subscribe as query parameter
        const hub = new URL(MERCURE_HUB_URL, window.origin);
        hub.searchParams.append('topic', `https://books.valdi.ovh/library/all`);
        // Subscribe to updates
        ws.current = new EventSource(hub, {withCredentials: true});
        ws.current.addEventListener('message', handleWebsocket);
    }

    /**
     * Will be called every time an update is published by the server
     * @param event
     */
    function handleWebsocket(event) {
        const json = JSON.parse(event.data);
        if (json.action === 'book:add') {
            refreshBooks();
        }
        if (json.action === 'book:recreate') {
            refreshBooks();
        }
        if (json.action === 'book:remove') {
            if (books.current.find(b => b.id === parseInt(json.book.id))) {
                refreshBooks();
            }
        }
    }

    function stopWebsocket() {
        if (ws.current) {
            ws.current.close();
        }
    }

    function loadMore() {
        setLoadingMore(true);
        getBooksAll(BOOKS_PER_PAGE + 1, BOOKS_PER_PAGE * page.current).then(
            res => {
                books.current.push(...res.data.slice(0, BOOKS_PER_PAGE));
                setHasMore(res.data.length > BOOKS_PER_PAGE);
                page.current++;
                setLoadingMore(false);
            },
            err => console.error(err)
        );
    }

    return (
        <>
            <Helmet>
                <title>All books</title>
            </Helmet>
            <div className="flex-grow-1 overflow-y-scroll border-top">
                {loading ? <LoadingComponent/> :
                    <div className="mx-0 row">
                        {books.current.map(book => <LibraryItem key={book.id} book={book}/>)}
                        <LibraryItemAdder hasMore={hasMore} loadMore={loadMore} loadingMore={loadingMore}/>
                    </div>
                }
            </div>
        </>
    );

}