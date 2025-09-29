import FileManager from "@CoreBundle/components/file-manager/FileManager";
import AppLayoutRouter from "@CoreBundle/components/layout/AppLayoutRouter";
import LibraryLayout from "@BooksBundle/components/library/LibraryLayout";
import BookLayout from "@BooksBundle/components/books/BookLayout";
import LibraryAll from "@BooksBundle/pages/LibraryAll";
import LibraryShelvesId from "@BooksBundle/pages/LibraryShelvesId";
import LibraryNotInShelves from "@BooksBundle/pages/LibraryNotInShelves";
import BookId from "@BooksBundle/pages/BookId";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import BackendApiContext from "@BooksBundle/components/BackendApiContext";
import createBackendApi from "@BooksBundle/components/BackendApi";
import { API_FILES_URL, API_URL, ROOT_URL } from "@BooksBundle/constants";
import { Navigate, Route } from "react-router-dom";
import React from "react";

export default function App() {
    const [libraryId, setLibraryId] = React.useState(1);
    const api = React.useMemo(() => createBackendApi(API_URL, libraryId), []);

    return <BackendApiContext value={api}>
        <AppLayoutRouter rootUrl={ROOT_URL}>
            <Route path="/" element={<Navigate to="/library/all"/>}/>
            <Route path="/library/all" element={
                <LibraryLayout>
                    <LibraryAll/>
                </LibraryLayout>
            }/>
            <Route path="/library/not-in-shelves" element={
                <LibraryLayout>
                    <LibraryNotInShelves/>
                </LibraryLayout>
            }/>
            <Route path="/library/shelves/:shelfId?" element={
                <LibraryLayout>
                    <LibraryShelvesId/>
                </LibraryLayout>
            }/>
            <Route path="/books/:bookId" element={
                <BookLayout>
                    <BookId/>
                </BookLayout>
            }/>
            <Route path="/files" element={
                <LibraryLayout>
                    <FileManager apiUrl={API_FILES_URL(libraryId)}/>
                </LibraryLayout>
            }/>
            <Route path="*" element={
                <LibraryLayout>
                    <NotFoundComponent redirectPath="/library/all" redirectText="Back Home"/>
                </LibraryLayout>
            }/>
        </AppLayoutRouter>
    </BackendApiContext>;

}