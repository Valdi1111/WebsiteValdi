import BookHeader from "@BooksBundle/components/books/header/BookHeader";
import BookFooter from "@BooksBundle/components/books/footer/BookFooter";
import BookBody from "@BooksBundle/components/books/body/BookBody";
import {
    FONT, FONTS, FONT_SIZE,
    SPACING, MARGINS, WIDTH,
    FORCE_FONT, FORCE_FONT_SIZE, JUSTIFY,
    LAYOUT, LAYOUTS,
    UPDATE_LAST_READ, isWheelAllowed
} from "@BooksBundle/components/books/BookConstants";
import {useBookSettings, useThemes} from "@BooksBundle/components/Contexts";
import {THEMES} from "@BooksBundle/components/ThemeConstants";
import {EPUB_URL, getBook, updatePosition} from "@BooksBundle/api/book";
import {useParams} from "react-router-dom";
import {Helmet} from "react-helmet";
import {Book, EpubCFI} from "epubjs";
import React from "react";
import $ from "jquery";
import "@BooksBundle/scss/iframe.css";

export default function BookId() {
    const [settings, setSetting] = useBookSettings();
    const [theme, setTheme] = useThemes();
    // book
    const book = React.useRef(null);
    const ready = React.useRef(false);
    const navigation = React.useRef([]);
    // State for loading screen
    const [loaded, setLoaded] = React.useState(false);
    const [title, setTitle] = React.useState('');
    // Book data
    const [mark, setMark] = React.useState({position: null, page: 0}); // current position and page
    const [chapter, setChapter] = React.useState(null); // current chapter
    const [section, setSection] = React.useState(null); // current section (from spine)
    const [location, setLocation] = React.useState(null);
    const [percentage, setPercentage] = React.useState(null);
    const {bookId} = useParams();

    /**
     * Handle layout updates
     */
    React.useEffect(() => {
        if (!ready.current) {
            return;
        }
        ready.current = false;
        updateLayout(mark);
    }, [settings[LAYOUT], settings[MARGINS]]);

    /**
     * Handle font settings updates
     */
    React.useEffect(() => {
        if (!ready.current) {
            return;
        }
        updateDefaultTheme();
    }, [
        settings[FONT],
        settings[FONT_SIZE],
        settings[SPACING],
        settings[JUSTIFY],
        settings[FORCE_FONT],
        settings[FORCE_FONT_SIZE]
    ]);

    /**
     * Handle dimension updates
     */
    React.useEffect(() => {
        if (!ready.current) {
            return;
        }
        const width = parseInt(settings[WIDTH]) + parseInt(settings[MARGINS]);
        book.current.rendition.resize(width, '100%');
    }, [settings[WIDTH]]);

    /**
     * Handle theme updates
     */
    React.useEffect(() => {
        if (!ready.current) {
            return;
        }
        updateTheme();
    }, [theme]);

    /**
     * Load book
     */
    React.useEffect(() => {
        if (book.current) {
            return;
        }
        getBook(bookId).then(
            res => {
                console.log("Loading book", bookId);
                book.current = new Book(`${EPUB_URL}/${res.data.id}`, {openAs: 'epub'});
                setTitle(res.data.book_metadata.title);
                navigation.current = res.data.book_cache.navigation;
                setMark(res.data.book_progress);
                // Generate locations
                book.current.ready.then(() => {
                    console.log("Loading locations...");
                    book.current.locations.load(res.data.book_cache.locations);
                    console.log("Locations loaded!");
                    document.onkeydown = onKeyDown;
                    updateLayout(res.data.book_progress);
                    setLoaded(true);
                });
            },
            err => console.error(err)
        );
    }, [bookId]);

    /**
     * Update position
     */
    React.useEffect(() => {
        if (!ready.current) {
            return;
        }
        // TODO setting for auto update last read
        const update = settings[UPDATE_LAST_READ] === 'true';
        updatePosition(bookId, mark.position, mark.page, update).then(
            res => console.debug("Position updated!"),
            err => console.error(err)
        );
    }, [mark]);

    /**
     * Render book
     * @param localMark {{position: string|null, page: int}} book's bookmark
     */
    function updateLayout(localMark) {
        const $area = $("#book-view");
        $area.empty();
        const gap = parseInt(settings[MARGINS]);
        const width = parseInt(settings[WIDTH]) + gap;
        let rendition = book.current.renderTo($area.get(0), {
            ...LAYOUTS[settings[LAYOUT]].settings,
            allowScriptedContent: true,
            width: width,
            height: '100%',
            gap: gap
        });
        if (!localMark.position) {
            rendition.display().then(r => ready.current = true);
        } else {
            rendition.display(localMark.position).then(r => ready.current = true);
        }
        rendition.on('relocated', updatePage);
        rendition.on('keydown', onKeyDown);
        // Open image view modal when clicking on img or image tag
        rendition.on('click', async e => {
            if (e.target.tagName.toLowerCase() === 'img' || e.target.tagName.toLowerCase() === 'image') {
                const {default: Modal} = await import("bootstrap/js/dist/modal");
                new Modal($('#image-view-modal')).show(e.target);
            }
        });
        // Turn page on mouse wheel
        rendition.hooks.content.register(contents => {
            if (!isWheelAllowed(settings[LAYOUT])) {
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
                const area = $('#book-view');
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
        updateDefaultTheme();
        Object.entries(THEMES).map(([id, value]) => rendition.themes.register(id, value.css));
        updateTheme();
    }

    /**
     * Update default book theme settings
     */
    function updateDefaultTheme() {
        const theme = {};
        theme['font-family'] = FONTS[settings[FONT]] + (settings[FORCE_FONT] === 'true' ? ' !important' : '');
        theme['font-size'] = settings[FONT_SIZE] + (settings[FORCE_FONT_SIZE] === 'true' ? 'px !important' : 'px');
        theme['line-height'] = settings[SPACING];
        theme['text-align'] = settings[JUSTIFY] === 'true' ? 'justify' : 'left';
        book.current.rendition.themes.default({body: theme});
    }

    /**
     * Update book theme
     */
    function updateTheme() {
        book.current.rendition.themes.select(theme);
    }

    function updatePage(loc) {
        const {cfi, href, displayed} = loc.start;
        // update current chapter
        setChapter(getChapFromCfi(loc.end.cfi));
        // update section
        setSection({current: book.current.spine.get(cfi).index, total: book.current.spine.last().index});
        // update location
        const page = book.current.locations.locationFromCfi(cfi);
        setLocation({current: page, total: book.current.locations.length()});
        // update percentage
        setPercentage(book.current.locations.percentageFromCfi(cfi));
        // update cache position
        setMark({position: cfi, page: page});
    }

    function flattenNav(items) {
        return [].concat.apply([], items.map(item => [].concat.apply([item], flattenNav(item.subitems))));
    }

    /**
     * Get navigation chapter from epub cfi if it exists, null otherwise.
     * @param cfi {string} the cfi
     * @returns {*} the chapter
     */
    function getChapFromCfi(cfi) {
        let prev = null;
        // TODO fix current chapter bug
        //let found = false;
        flattenNav(navigation.current).forEach(s => {
            if (s.cfi !== null) {
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
            }
        })
        return prev;
    }

    /**
     * Search a string inside spine.
     * @param item the spine item
     * @param value the string to search
     * @returns {Promise<*[]>} an array of results
     */
    function searchSpine(item, value) {
        return item.load(book.current.load.bind(book.current))
            .then(item.find.bind(item, value))
            .finally(item.unload.bind(item))
            .then(elems => elems.map(e => {
                e.chapter = getChapFromCfi(e.cfi);
                return e;
            }));
    }

    /**
     * Search a string inside the book
     * @param value the string to search
     * @param all true to search inside all the book, false to search only inside the current chapter
     * @returns {Promise<*[]>}
     */
    function search(value, all) {
        if (all) {
            return Promise.all(book.current.spine.spineItems.map(item => searchSpine(item, value)))
                .then(results => Promise.resolve([].concat.apply([], results)));
        }
        const item = book.current.spine.get(book.current.rendition.location.start.cfi);
        return searchSpine(item, value);
    }

    /**
     * Navigate to location
     * @param href {string} book location
     */
    function navigateTo(href) {
        book.current.rendition.display(href).then(res => console.debug("Navigate", href));
    }

    /**
     * Handle arrow right/left to navigate book pages
     * @param e event
     */
    function onKeyDown(e) {
        let code = e.keyCode || e.which;
        if (code === 37) {
            prev();
        }
        if (code === 39) {
            next();
        }
    }

    /**
     * Go to previous page
     */
    function prev() {
        if (!ready.current) {
            return;
        }
        book.current.rendition.prev();
    }

    /**
     * Go to next page
     */
    function next() {
        if (!ready.current) {
            return;
        }
        book.current.rendition.next();
    }

    return (
        <>
            <Helmet>
                <title>{title}</title>
            </Helmet>
            <BookHeader title={title} chapter={chapter} navigation={navigation.current} navigateTo={navigateTo}
                        search={search}/>
            <BookBody loaded={loaded}/>
            <BookFooter chapter={chapter} section={section} location={location} percentage={percentage} prev={prev}
                        next={next}/>
        </>
    );

}