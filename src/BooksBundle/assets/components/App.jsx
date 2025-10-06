import FileManager from "@CoreBundle/components/file-manager/FileManager";
import LibraryLayout from "@BooksBundle/components/library/LibraryLayout";
import BookLayout from "@BooksBundle/components/books/BookLayout";
import LibraryAll from "@BooksBundle/pages/LibraryAll";
import LibraryShelvesId from "@BooksBundle/pages/LibraryShelvesId";
import LibraryNotInShelves from "@BooksBundle/pages/LibraryNotInShelves";
import BookId from "@BooksBundle/pages/BookId";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import BackendApiContext from "@BooksBundle/components/BackendApiContext";
import createBackendApi from "@BooksBundle/components/BackendApi";
import { API_URL, ROOT_URL } from "@BooksBundle/constants";
import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import { App as AntdApp } from "antd";
import React from "react";

export default function App() {
    const [libraryId, setLibraryId] = React.useState(1);
    const app = AntdApp.useApp();
    const api = React.useMemo(() => createBackendApi(API_URL, libraryId, app), [libraryId]);

    return <BackendApiContext value={api}>
        <BrowserRouter basename={ROOT_URL}>
            <Routes>
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
                        <FileManager apiUrl={api.fmUrl()}/>
                    </LibraryLayout>
                }/>
                <Route path="*" element={
                    <LibraryLayout>
                        <NotFoundComponent redirectPath="/library/all" redirectText="Back Home"/>
                    </LibraryLayout>
                }/>
            </Routes>
        </BrowserRouter>
    </BackendApiContext>;

}