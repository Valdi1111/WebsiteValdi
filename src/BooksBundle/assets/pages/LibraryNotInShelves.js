import {useLibraryUpdate} from "../components/Contexts";
import {BOOKS_PER_PAGE, getBooksNotInShelf} from "../api/library";
import LibraryItemAdder from "../components/library/item/LibraryItemAdder";
import LibraryItem from "../components/library/item/LibraryItem";
import LoadingComponent from "../components/LoadingComponent";
import {Helmet} from "react-helmet";
import React from "react";

export default function LibraryNotInShelves() {
    const [update, setUpdate] = useLibraryUpdate();
    const [hasMore, setHasMore] = React.useState(false);
    const [books, setBooks] = React.useState([]);
    const [page, setPage] = React.useState(1);
    const [loading, setLoading] = React.useState(true);
    /** @type {MutableRefObject<EventSource>}*/
    const ws = React.useRef(null);

    React.useEffect(() => {
        refreshBooks();
        return stopWebsocket;
    }, []);

    // Handle book add/recreate/delete
    React.useEffect(() => {
        if (update.type === 'add') {
            let flag = false;
            for (const item of update.items) {
                if (item.shelf_id === null) {
                    flag = true;
                }
            }
            if (flag) {
                refreshBooks();
            }
        }
        if (update.type === 'recreate') {
            if (update.shelf_id === null) {
                refreshBooks();
            }
        }
        if (update.type === 'delete') {
            if (books.filter(b => b.id === parseInt(update.id)).length === 1) {
                refreshBooks();
            }
        }
    }, [update]);

    function refreshBooks() {
        setLoading(true);
        getBooksNotInShelf(BOOKS_PER_PAGE * page + 1, 0).then(
            res => {
                setBooks(res.data.slice(0, BOOKS_PER_PAGE * page));
                setHasMore(res.data.length > BOOKS_PER_PAGE * page);
                setLoading(false);
                // Websocket
                startWebsocket();
            },
            err => console.error(err)
        );
    }

    function startWebsocket() {
        // Append the topic(s) to subscribe as query parameter
        const hub = new URL(MERCURE_HUB_URL, window.origin);
        hub.searchParams.append('topic', `https://books.valdi.ovh/library/not-in-shelves`);
        // Subscribe to updates
        ws.current = new EventSource(hub, {withCredentials: true});
        ws.current.onmessage = event => {
            // Will be called every time an update is published by the server
            console.log(JSON.parse(event.data));
        }
    }

    function stopWebsocket() {
        if (ws.current) {
            ws.current.close();
        }
    }

    function loadMore() {
        getBooksNotInShelf(BOOKS_PER_PAGE + 1, BOOKS_PER_PAGE * page).then(
            res => {
                setBooks([...books, ...res.data.slice(0, BOOKS_PER_PAGE)]);
                setHasMore(res.data.length > BOOKS_PER_PAGE);
                setPage(page + 1);
            },
            err => console.error(err)
        );
    }

    return (
        <>
            <Helmet>
                <title>Not in shelves</title>
            </Helmet>
            <div className="flex-grow-1 overflow-y-scroll">
                {loading ? <LoadingComponent/> :
                    <div className="mx-0 row">
                        {books.map(book => <LibraryItem key={book.id} book={book}/>)}
                        <LibraryItemAdder hasMore={hasMore} loadMore={loadMore}/>
                    </div>
                }
            </div>
        </>
    );

}