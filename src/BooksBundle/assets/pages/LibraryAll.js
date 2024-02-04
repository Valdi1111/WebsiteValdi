import {useLibraryUpdate} from "../components/Contexts";
import {BOOKS_PER_PAGE, getBooksAll} from "../api/library";
import LibraryItemAdder from "../components/library/item/LibraryItemAdder";
import LibraryItem from "../components/library/item/LibraryItem";
import LoadingComponent from "../components/LoadingComponent";
import {Helmet} from "react-helmet";
import React from "react";

export default function LibraryAll() {
    const [update, setUpdate] = useLibraryUpdate();
    const [hasMore, setHasMore] = React.useState(false);
    const [books, setBooks] = React.useState([]);
    const [page, setPage] = React.useState(1);
    const [loading, setLoading] = React.useState(true);

    React.useEffect(() => {
        refreshBooks();
    }, []);

    // Handle book add/recreate/delete
    React.useEffect(() => {
        if (update.type !== 'add' && update.type !== 'recreate' && update.type !== 'delete') {
            return;
        }
        refreshBooks();
    }, [update]);

    function refreshBooks() {
        setLoading(true);
        getBooksAll(BOOKS_PER_PAGE * page + 1, 0).then(
            res => {
                setBooks(res.data.slice(0, BOOKS_PER_PAGE * page));
                setHasMore(res.data.length > BOOKS_PER_PAGE * page);
                setLoading(false);
            },
            err => console.error(err)
        );
    }

    function loadMore() {
        getBooksAll(BOOKS_PER_PAGE + 1, BOOKS_PER_PAGE * page).then(
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
                <title>All books</title>
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