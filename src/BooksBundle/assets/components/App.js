import {BrowserRouter, Navigate, Route, Routes} from "react-router-dom";
import ThemeChangeModal from "./library/modals/theme/ThemeChangeModal";
import LibraryLayout from "./library/LibraryLayout";
import LibraryAll from "../pages/LibraryAll";
import LibraryNotInShelves from "../pages/LibraryNotInShelves";
import LibraryShelvesLayout from "./library/shelves/LibraryShelvesLayout";
import LibraryShelvesId from "../pages/LibraryShelvesId";
import BookLayout from "./books/BookLayout";
import BookId from "../pages/BookId";
import {THEMES, THEME} from "./ThemeConstants";
import {ThemeContext} from "./Contexts";
import * as constants from "../constants";
import React from 'react';

export default function App() {
    const [theme, setTheme] = React.useState(localStorage.getItem(THEME) || Object.keys(THEMES)[0]);

    // Load theme or set default
    React.useEffect(() => {
        if (!theme) {
            return;
        }
        localStorage.setItem(THEME, theme);
        const elem = document.getElementsByTagName('html')
        if (!elem || elem.length !== 1) {
            return;
        }
        elem[0].setAttribute('data-bs-theme', theme);
    }, [theme]);

    /*
    React.useEffect(() => {
        // Append the topic(s) to subscribe as query parameter
        const hub = new URL(MERCURE_HUB_URL, window.origin);
        hub.searchParams.append('topic', 'https://books.valdi.ovh/books/updates');

        // Subscribe to updates
        const eventSource = new EventSource(hub, {withCredentials: true});
        eventSource.onmessage = event => {
            // Will be called every time an update is published by the server
            console.log(JSON.parse(event.data));
        }
    }, []);
    */

    return (
        <ThemeContext.Provider value={[theme, setTheme]}>
            <ThemeChangeModal/>
            <div className="d-flex flex-column vh-100">
                <BrowserRouter basename={constants.ROOT_URL}>
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
                        <Route path="/library/shelves/:shelfId" element={
                            <LibraryShelvesLayout>
                                <LibraryShelvesId/>
                            </LibraryShelvesLayout>
                        }/>
                        <Route path="/library/shelves" element={
                            <LibraryShelvesLayout>
                            </LibraryShelvesLayout>
                        }/>
                        <Route path="/books/:bookId" element={
                            <BookLayout>
                                <BookId/>
                            </BookLayout>
                        }/>
                    </Routes>
                </BrowserRouter>
            </div>
        </ThemeContext.Provider>
    );

}