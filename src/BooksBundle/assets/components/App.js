import {BrowserRouter, Navigate, Route, Routes} from "react-router-dom";
import ThemeChangeModal from "@BooksBundle/components/library/modals/theme/ThemeChangeModal";
import LibraryShelvesLayout from "@BooksBundle/components/library/shelves/LibraryShelvesLayout";
import LibraryLayout from "@BooksBundle/components/library/LibraryLayout";
import BookLayout from "@BooksBundle/components/books/BookLayout";
import LibraryAll from "@BooksBundle/pages/LibraryAll";
import LibraryShelvesId from "@BooksBundle/pages/LibraryShelvesId";
import LibraryNotInShelves from "@BooksBundle/pages/LibraryNotInShelves";
import BookId from "@BooksBundle/pages/BookId";
import {THEMES, THEME} from "@BooksBundle/components/ThemeConstants";
import {ThemeContext} from "@BooksBundle/components/Contexts";
import * as constants from "@BooksBundle/constants";
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