import BookHeader from "@BooksBundle/components/books/BookHeader";
import BookFooter from "@BooksBundle/components/books/BookFooter";
import BookBody from "@BooksBundle/components/books/BookBody";
import BookContext from "@BooksBundle/components/books/BookContext";
import { useThemes } from "@CoreBundle/components/theme/ThemeContext";
import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { useParams } from "react-router";
import { Book, EpubCFI } from "epubjs";
import React from "react";
import "@BooksBundle/scss/iframe.css";
import {
    FONT, FONTS, FONT_SIZE,
    SPACING, MARGINS, WIDTH,
    FORCE_FONT, FORCE_FONT_SIZE, JUSTIFY,
    LAYOUT, LAYOUTS,
    UPDATE_LAST_READ
} from "@BooksBundle/components/books/BookConstants";
import { theme as antdTheme } from "antd";

export default function BookId() {
    const { token: { colorBgElevated, colorText, colorLink, colorLinkHover } } = antdTheme.useToken();
    const [contentsDrawerOpen, setContentsDrawerOpen] = React.useState(false);
    const [settingsDrawerOpen, setSettingsDrawerOpen] = React.useState(false);
    const { settings, setSetting } = useBookSettings();
    const [theme, setTheme] = useThemes();
    // Book
    const book = React.useRef(null);
    const ready = React.useRef(false);
    // State for loading screen
    const [loading, setLoading] = React.useState(true);
    // Book mark (position and page)
    const [mark, setMark] = React.useState({ position: null, page: 0 });
    // Book data
    const [title, setTitle] = React.useState('');
    const [navigation, setNavigation] = React.useState([]);
    const [chapter, setChapter] = React.useState(null);
    const [section, setSection] = React.useState(null);
    const [location, setLocation] = React.useState(null);
    const [percentage, setPercentage] = React.useState(null);

    const api = useBackendApi();
    const { bookId } = useParams();

    /**
     * Handle layout updates
     */
    React.useEffect(() => {
        if (!ready.current) {
            return;
        }
        ready.current = false;
        updateLayout().then(() => ready.current = true);
    }, [theme, settings]);

    /**
     * Load book
     */
    React.useEffect(() => {
        setLoading(true);
        ready.current = false;
        api
            .withErrorHandling()
            .books()
            .getId(bookId)
            .then(res => {
                console.debug("Loading book", bookId);
                book.current = new Book(api.books().epubUrl(res.data.id), { openAs: 'epub' })
                setTitle(res.data.book_metadata.title);
                setNavigation(res.data.book_cache.navigation);
                setMark(res.data.book_progress);
                // Generate locations
                book.current.ready.then(() => {
                    console.debug("Loading locations...");
                    book.current.locations.load(res.data.book_cache.locations);
                    console.debug("Locations loaded!");
                    document.onkeydown = onKeyDown;
                    setLoading(false);
                });
            });
    }, [bookId]);

    React.useEffect(() => {
        if (loading) {
            return;
        }
        updateLayout().then(() => ready.current = true);
    }, [loading]);

    /**
     * Update position
     */
    React.useEffect(() => {
        if (!ready.current) {
            return;
        }
        // TODO setting for auto update last read
        const update = settings[UPDATE_LAST_READ] === 'true';
        api
            .withErrorHandling()
            .books()
            .updatePosition(bookId, mark.position, mark.page, update)
            .then(
                res => console.debug("Position updated!")
            );
    }, [mark]);

    /**
     * Render book
     * @param localMark {{position: string|null, page: int}} book's bookmark
     */
    const updateLayout = React.useCallback(async () => {
        console.log("Updating layout...");
        const area = document.getElementById('book-view');
        area.innerHTML = '';
        const gap = parseInt(settings[MARGINS]);
        const width = parseInt(settings[WIDTH]) + gap;
        const rendition = book.current.renderTo(area, {
            ...LAYOUTS[settings[LAYOUT]].settings,
            allowScriptedContent: true,
            width: width,
            height: '100%',
            gap: gap
        });
        rendition.on('relocated', updatePage);
        rendition.on('keydown', onKeyDown);
        // Open image view modal when clicking on img or image tag
        rendition.on('click', async e => {
            // TODO convertire a Image di antd
            if (e.target.tagName.toLowerCase() === 'img' || e.target.tagName.toLowerCase() === 'image') {
                const { default: Modal } = await import("bootstrap/js/dist/modal");
                new Modal(document.getElementById('image-view-modal')).show(e.target);
            }
        });
        // Turn page on mouse wheel
        rendition.hooks.content.register(contents => {
            if (settings[LAYOUT] !== 'auto' && settings[LAYOUT] !== 'single') {
                return;
            }
            contents.documentElement.onwheel = e => {
                if (e.deltaY < 0) {
                    prev();
                }
                if (e.deltaY > 0) {
                    next();
                }
            }
        });
        // Turn page on touch swipe
        rendition.hooks.content.register(contents => {
            let start, end;
            contents.documentElement.ontouchstart = e => {
                start = e.changedTouches[0];
            }
            contents.documentElement.ontouchend = e => {
                end = e.changedTouches[0];
                const area = document.getElementById('book-view');
                if (area) {
                    const bound = area.getBoundingClientRect();
                    const hr = (end.screenX - start.screenX) / bound.width;
                    const vr = Math.abs((end.screenY - start.screenY) / bound.height);
                    if (hr > 0.1 && vr < 0.1) {
                        prev();
                    }
                    if (hr < -0.1 && vr < 0.1) {
                        next();
                    }
                }
            }
        });
        // Hide cursor after 3 seconds
        rendition.hooks.content.register(contents => {
            let mouseTimer = null;
            let cursorVisible = true;
            contents.documentElement.onmousemove = e => {
                if (mouseTimer) {
                    window.clearTimeout(mouseTimer);
                }
                if (!cursorVisible) {
                    contents.documentElement.style.cursor = 'default';
                    cursorVisible = true;
                }
                mouseTimer = window.setTimeout(() => {
                    mouseTimer = null;
                    contents.documentElement.style.cursor = 'none';
                    cursorVisible = false;
                }, 3000);
            }
        });
        // Update default theme
        const defaultCss = {
            body: {
                background: colorBgElevated,
                color: colorText,
                'font-family': FONTS[settings[FONT]] + (settings[FORCE_FONT] === 'true' ? ' !important' : ''),
                'font-size': settings[FONT_SIZE] + (settings[FORCE_FONT_SIZE] === 'true' ? 'px !important' : 'px'),
                'line-height': settings[SPACING],
                'text-align': settings[JUSTIFY] === 'true' ? 'justify' : 'left',
            },
            a: {
                color: colorLink,
            },
            'a:hover': {
                color: colorLinkHover,
            },
            'a:active': {
                color: colorLinkHover,
            },
        };
        book.current.rendition.themes.default(defaultCss);
        // Display
        if (!mark.position) {
            await rendition.display();
        } else {
            await rendition.display(mark.position);
        }
        console.log(book.current);
        console.log(rendition);
    }, [settings, theme, mark, navigation]);

    const updatePage = React.useCallback((loc) => {
        const { cfi, href, displayed } = loc.start;
        // update current chapter
        setChapter(getChapFromCfi(loc.end.cfi));
        // update section
        setSection({ current: book.current.spine.get(cfi).index, total: book.current.spine.last().index });
        // update location
        const page = book.current.locations.locationFromCfi(cfi);
        setLocation({ current: page, total: book.current.locations.length() });
        // update percentage
        setPercentage(book.current.locations.percentageFromCfi(cfi));
        // update cache position
        setMark({ position: cfi, page: page });
    }, [navigation]);

    /**
     * Get navigation chapter from epub cfi if it exists, null otherwise.
     * @param cfi {string} the cfi
     * @returns {*} the chapter
     */
    const getChapFromCfi = React.useCallback((cfi) => {

        function flattenNav(items) {
            return [].concat.apply([], items.map(item => [].concat.apply([item], flattenNav(item.subitems))));
        }

        let prev = null;
        // TODO fix current chapter bug
        //let found = false;
        flattenNav(navigation).forEach(s => {
            if (s.cfi === null) {
                return;
            }
            //console.log(cfi, s);
            if (new EpubCFI().compare(cfi, s.cfi) === -1) {
                //if(prev && !found) {
                //    found = true;
                //}
                return;
            }
            //if(!found) {
            prev = s;
            //}
        })
        return prev;
    }, [navigation]);

    /**
     * Search a string inside spine.
     * @param item the spine item
     * @param value the string to search
     * @returns {Promise<*[]>} an array of results
     */
    const searchSpine = React.useCallback((item, value) => {
        return item.load(book.current.load.bind(book.current))
            .then(item.find.bind(item, value))
            .finally(item.unload.bind(item))
            .then(elems => elems.map(e => {
                e.chapter = getChapFromCfi(e.cfi);
                return e;
            }));
    }, [navigation]);

    /**
     * Search a string inside the book
     * @param value the string to search
     * @param all true to search inside all the book, false to search only inside the current chapter
     * @returns {Promise<*[]>}
     */
    const search = React.useCallback((value, all) => {
        if (all) {
            return Promise.all(book.current.spine.spineItems.map(item => searchSpine(item, value)))
                .then(results => Promise.resolve([].concat.apply([], results)));
        }
        const item = book.current.spine.get(book.current.rendition.location.start.cfi);
        return searchSpine(item, value);
    }, [navigation]);

    /**
     * Navigate to location
     * @param href {string} book location
     */
    const navigateTo = React.useCallback((href) => {
        book.current.rendition.display(href).then(res => console.debug("Navigate", href));
    }, []);

    /**
     * Handle arrow right/left to navigate book pages
     * @param e event
     */
    const onKeyDown = React.useCallback((e) => {
        let code = e.keyCode || e.which;
        if (code === 37) {
            prev();
        }
        if (code === 39) {
            next();
        }
    }, []);

    /**
     * Go to the previous page
     */
    const prev = React.useCallback(() => {
        if (!book.current) {
            return;
        }
        book.current.rendition.prev();
    }, []);

    /**
     * Go to the next page
     */
    const next = React.useCallback(() => {
        if (!book.current) {
            return;
        }
        book.current.rendition.next();
    }, []);

    return <BookContext value={{
        title, setTitle,
        navigation, setNavigation,
        chapter, setChapter,
        section, setSection,
        location, setLocation,
        percentage, setPercentage,
        navigateTo, search,
        prev, next,
        contentsDrawerOpen, setContentsDrawerOpen,
        settingsDrawerOpen, setSettingsDrawerOpen,
    }}>
        <title>{title}</title>
        <BookHeader/>
        <BookBody loading={loading}/>
        <BookFooter/>
    </BookContext>;

}