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
// import "@BooksBundle/styles/iframe.css";
import {
    FORCE_TEXT_COLOR, FONT, FONTS, FONT_SIZE,
    SPACING, MARGINS, WIDTH,
    FORCE_FONT, FORCE_FONT_SIZE, JUSTIFY,
    LAYOUT, LAYOUTS,
    UPDATE_LAST_READ
} from "@BooksBundle/components/books/BookConstants";
import { theme as antdTheme } from "antd";
import ImageViewModal from "@BooksBundle/components/books/modals/ImageViewModal.jsx";

export default function BookId() {
    const { token: { colorBgElevated, colorText, colorLink, colorLinkHover } } = antdTheme.useToken();
    const [contentsDrawerOpen, setContentsDrawerOpen] = React.useState(false);
    const [settingsDrawerOpen, setSettingsDrawerOpen] = React.useState(false);
    const { settings, setSetting } = useBookSettings();
    const [theme, setTheme] = useThemes();

    // Book, rendition and container DOM refs
    const book = React.useRef(null);
    const renditionRef = React.useRef(null);
    const viewerRef = React.useRef(null);
    const isRendering = React.useRef(false);

    // State for loading screen
    const [loading, setLoading] = React.useState(true);

    // Book mark (position and page)
    const [mark, setMark] = React.useState({ position: null, page: 0 });
    const markRef = React.useRef(mark);
    markRef.current = mark;

    // Book data
    const [title, setTitle] = React.useState('');
    const [navigation, setNavigation] = React.useState([]);
    const [chapter, setChapter] = React.useState(null);
    const [section, setSection] = React.useState(null);
    const [location, setLocation] = React.useState(null);
    const [percentage, setPercentage] = React.useState(null);

    // Image modal
    const [imageModal, setImageModal] = React.useState({ open: false, src: '', alt: '' });

    // Track pending unsaved progress
    const pendingMarkRef = React.useRef(null);
    const settingsRef = React.useRef(settings);
    settingsRef.current = settings;

    const api = useBackendApi();
    const { bookId } = useParams();

    // Memoized flattened navigation tree
    const flatNavigation = React.useMemo(() => {
        function flattenNav(items) {
            return [].concat.apply([], items.map(item => [].concat.apply([item], flattenNav(item.subitems || []))));
        }
        return flattenNav(navigation);
    }, [navigation]);

    /**
     * Get navigation chapter from epub cfi if it exists, null otherwise.
     * @param cfi {string} the cfi
     * @returns {*} the chapter
     */
    const getChapFromCfi = React.useCallback((cfi) => {
        let prev = null;
        // TODO fix current chapter bug
        //let found = false;
        flatNavigation.forEach(s => {
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
        });
        return prev;
    }, [flatNavigation]);

    /**
     * Go to the previous page
     */
    const prev = React.useCallback(() => {
        if (!renditionRef.current) {
            return;
        }
        renditionRef.current.prev();
    }, []);

    /**
     * Go to the next page
     */
    const next = React.useCallback(() => {
        if (!renditionRef.current) {
            return;
        }
        renditionRef.current.next();
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
    }, [prev, next]);

    // Attach global keyboard listener with cleanup
    React.useEffect(() => {
        window.addEventListener('keydown', onKeyDown);
        return () => {
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [onKeyDown]);

    const updatePage = React.useCallback((loc) => {
        if (!loc || !loc.start || !book.current) {
            return;
        }
        const { cfi } = loc.start;
        // update current chapter
        setChapter(getChapFromCfi(loc.end.cfi));
        // update section
        const spine = book.current.spine;
        const currentItem = spine.get(cfi);
        const lastItem = spine.last();
        setSection({
            current: currentItem ? currentItem.index : 0,
            total: lastItem ? lastItem.index : 0
        });
        // update location
        const page = book.current.locations.locationFromCfi(cfi);
        setLocation({ current: page, total: book.current.locations.length() });
        // update percentage
        setPercentage(book.current.locations.percentageFromCfi(cfi));
        // update cache position
        setMark({ position: cfi, page: page });
    }, [getChapFromCfi]);

    /**
     * Build raw CSS string for book theme to ensure clean overrides
     */
    const getComputedCssString = React.useCallback(() => {
        const isForceText = settings[FORCE_TEXT_COLOR] === 'true';
        const isForceFont = settings[FORCE_FONT] === 'true';
        const isForceFontSize = settings[FORCE_FONT_SIZE] === 'true';
        const isJustify = settings[JUSTIFY] === 'true';

        const fontFamily = FONTS[settings[FONT]] || 'inherit';
        const fontSize = settings[FONT_SIZE] || '18';
        const spacing = settings[SPACING] || '1.4';

        let css = `
            html, body {
                background-color: ${colorBgElevated} !important;
                background: ${colorBgElevated} !important;
                color: ${colorText} ${isForceText ? '!important' : ''};
                font-family: ${fontFamily} ${isForceFont ? '!important' : ''};
                font-size: ${fontSize}px ${isForceFontSize ? '!important' : ''};
                line-height: ${spacing};
                text-align: ${isJustify ? 'justify' : 'left'};
            }
            a {
                color: ${colorLink} !important;
            }
            a:hover, a:active {
                color: ${colorLinkHover} !important;
            }
        `;

        if (isForceText) {
            css += `
                p, span, div, li, h1, h2, h3, h4, h5, h6 {
                    color: ${colorText} !important;
                }
            `;
        }

        if (isForceFont) {
            css += `
                p, span, div, li, h1, h2, h3, h4, h5, h6 {
                    font-family: ${fontFamily} !important;
                }
            `;
        }

        return css;
    }, [colorBgElevated, colorText, colorLink, colorLinkHover, settings]);

    // Keep CSS string updated in a ref so newly loaded section hooks always use the latest
    const cssStringRef = React.useRef('');
    cssStringRef.current = getComputedCssString();

    /**
     * Helper to inject or completely replace custom theme stylesheet in a document
     */
    const injectStylesheetToDoc = React.useCallback((doc, cssString) => {
        if (!doc || !doc.head) return;
        let styleTag = doc.getElementById('epub-dynamic-theme');
        if (!styleTag) {
            styleTag = doc.createElement('style');
            styleTag.id = 'epub-dynamic-theme';
            styleTag.type = 'text/css';
            doc.head.appendChild(styleTag);
        }
        // Completely replace text content to wipe out previously active !important rules
        styleTag.textContent = cssString;

        // Clean any leftover inline overrides on body
        if (doc.body) {
            doc.body.style.backgroundColor = colorBgElevated;
            doc.body.style.color = '';
        }
    }, [colorBgElevated]);

    /**
     * Apply styles live without rebuilding the rendition
     */
    const updateThemeStyles = React.useCallback(() => {
        if (!renditionRef.current) {
            return;
        }

        const cssString = getComputedCssString();

        // Update container background directly
        if (viewerRef.current) {
            viewerRef.current.style.backgroundColor = colorBgElevated;
        }

        // Overwrite or create <style id="epub-dynamic-theme"> in all loaded iframe documents
        if (renditionRef.current.getContents) {
            renditionRef.current.getContents().forEach(c => {
                if (c.document) {
                    injectStylesheetToDoc(c.document, cssString);
                }
            });
        }
    }, [getComputedCssString, colorBgElevated, settings, injectStylesheetToDoc]);

    /**
     * Initialize rendition once when book is ready
     */
    const initializeRendition = React.useCallback(async () => {
        console.log("Updating layout...");
        const area = viewerRef.current;
        if (!area || !book.current || isRendering.current) {
            return;
        }
        isRendering.current = true;

        // Ensure container is clean
        area.innerHTML = '';

        const gap = parseInt(settings[MARGINS]) || 0;
        const width = (parseInt(settings[WIDTH]) || 1000) + gap;

        const layoutConfig = LAYOUTS[settings[LAYOUT]]?.settings || {};
        const rendition = book.current.renderTo(area, {
            ...layoutConfig,
            allowScriptedContent: true,
            width: width,
            height: '100%',
            gap: gap
        });
        renditionRef.current = rendition;

        rendition.on('relocated', updatePage);
        rendition.on('keydown', onKeyDown);

        // Open image view modal when clicking on img or image tag
        rendition.on('click', e => {
            const target = e.target;
            const tag = target.tagName ? target.tagName.toLowerCase() : '';
            if (tag === 'img') {
                setImageModal({
                    open: true,
                    src: target.src,
                    alt: target.alt || 'Image from book'
                });
            } else if (tag === 'image') {
                setImageModal({
                    open: true,
                    src: target.getAttribute('xlink:href') || target.getAttribute('href'),
                    alt: 'Image from book'
                });
            }
        });

        // Automatically inject current dynamic style whenever new chapter content loads
        rendition.hooks.content.register(contents => {
            if (contents.document) {
                injectStylesheetToDoc(contents.document, cssStringRef.current);
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
            };
        });

        // Turn page on touch swipe
        rendition.hooks.content.register(contents => {
            let start, end;
            contents.documentElement.ontouchstart = e => {
                start = e.changedTouches[0];
            };
            contents.documentElement.ontouchend = e => {
                end = e.changedTouches[0];
                const boundArea = viewerRef.current;
                if (boundArea) {
                    const bound = boundArea.getBoundingClientRect();
                    const hr = (end.screenX - start.screenX) / bound.width;
                    const vr = Math.abs(end.screenY - start.screenY) / bound.height;
                    if (hr > 0.1 && vr < 0.1) {
                        prev();
                    }
                    if (hr < -0.1 && vr < 0.1) {
                        next();
                    }
                }
            };
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
            };
        });

        // Set container background
        area.style.backgroundColor = colorBgElevated;

        // Display
        const currentPosition = markRef.current?.position;
        if (!currentPosition) {
            await rendition.display();
        } else {
            await rendition.display(currentPosition);
        }

        isRendering.current = false;
    }, [settings, onKeyDown, prev, next, updatePage, colorBgElevated, injectStylesheetToDoc]);

    /**
     * Load book
     */
    React.useEffect(() => {
        setLoading(true);
        let active = true;

        api
            .withErrorHandling()
            .books()
            .getId(bookId)
            .then(res => {
                if (!active) {
                    return;
                }
                console.debug("Loading book", bookId);
                const newBook = new Book(api.books().epubUrl(res.data.id), { openAs: 'epub' });
                book.current = newBook;
                setTitle(res.data.book_metadata.title);
                setNavigation(res.data.book_cache.navigation);
                setMark(res.data.book_progress);
                // Generate locations
                newBook.ready.then(() => {
                    if (!active) {
                        return;
                    }
                    console.debug("Loading locations...");
                    newBook.locations.load(res.data.book_cache.locations);
                    console.debug("Locations loaded!");
                    setLoading(false);
                });
            });

        return () => {
            active = false;
            if (book.current) {
                book.current.destroy();
                book.current = null;
            }
            renditionRef.current = null;
            isRendering.current = false;
        };
    }, [bookId, api]);

    // Initial render when book finished loading
    React.useEffect(() => {
        if (!loading && book.current && !renditionRef.current) {
            initializeRendition();
        }
    }, [loading, initializeRendition]);

    /**
     * Handle size/margin updates dynamically without re-rendering or duplicating views
     */
    React.useEffect(() => {
        if (!renditionRef.current) {
            return;
        }
        console.debug("Resizing layout...", { margins: settings[MARGINS], width: settings[WIDTH] });
        const gap = parseInt(settings[MARGINS]) || 0;
        const width = (parseInt(settings[WIDTH]) || 1000) + gap;
        renditionRef.current.resize(width, '100%');
    }, [settings[MARGINS], settings[WIDTH]]);

    /**
     * Handle layout mode updates dynamically
     */
    React.useEffect(() => {
        if (!renditionRef.current) {
            return;
        }
        console.debug("Updating layout mode...", settings[LAYOUT]);
        const layoutConfig = LAYOUTS[settings[LAYOUT]]?.settings;
        if (layoutConfig) {
            if (layoutConfig.flow) {
                renditionRef.current.flow(layoutConfig.flow);
            }
            if (layoutConfig.spread) {
                renditionRef.current.spread(layoutConfig.spread);
            }
        }
    }, [settings[LAYOUT]]);

    /**
     * Handle theme/style changes on the fly
     */
    React.useEffect(() => {
        updateThemeStyles();
    }, [
        theme,
        settings[FONT],
        settings[FONT_SIZE],
        settings[FORCE_FONT],
        settings[FORCE_FONT_SIZE],
        settings[FORCE_TEXT_COLOR],
        settings[JUSTIFY],
        settings[SPACING],
        colorBgElevated,
        colorText,
        colorLink,
        colorLinkHover,
        updateThemeStyles
    ]);

    /**
     * Flush/save position immediately to backend
     */
    const flushPositionUpdate = React.useCallback(() => {
        const pending = pendingMarkRef.current;
        if (!pending || !pending.position) {
            return;
        }

        const update = settingsRef.current[UPDATE_LAST_READ] === 'true';
        console.debug("Flushing position update to backend:", pending);

        api
            .withErrorHandling()
            .books()
            .updatePosition(bookId, pending.position, pending.page, update)
            .then(() => {
                console.debug("Position flush completed!");
                // Clear pending mark if it hasn't changed in the meantime
                if (pendingMarkRef.current === pending) {
                    pendingMarkRef.current = null;
                }
            })
            .catch(err => {
                console.error("Failed to flush position:", err);
            });
    }, [api, bookId]);

    /**
     * Debounced position update + guaranteed flush on unmount and page exit
     */
    React.useEffect(() => {
        if (!mark.position || loading) {
            return;
        }

        // Record the latest position as pending
        pendingMarkRef.current = mark;

        // Debounce update during active reading
        const timer = setTimeout(() => {
            flushPositionUpdate();
        }, 800);

        return () => {
            clearTimeout(timer);
        };
    }, [mark, loading, flushPositionUpdate]);

    /**
     * Handle browser tab close, window reload or external navigation
     */
    React.useEffect(() => {
        const handlePageHide = () => {
            const pending = pendingMarkRef.current;
            if (!pending || !pending.position) {
                return;
            }

            const update = settingsRef.current[UPDATE_LAST_READ] === 'true';
            const payload = JSON.stringify({
                position: pending.position,
                page: pending.page,
                update_last_read: update,
            });

            // If backend URL endpoint is known via api helper, use beacon or keepalive fetch:
            // Example: api.books().updatePositionUrl(bookId)
            const endpoint = `/api/books/${bookId}/position`;

            if (navigator.sendBeacon) {
                const blob = new Blob([payload], { type: 'application/json' });
                navigator.sendBeacon(endpoint, blob);
            } else {
                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: payload,
                    keepalive: true,
                }).catch(() => {});
            }
        };

        // Listen for browser close or navigation away from page
        window.addEventListener('pagehide', handlePageHide);

        return () => {
            window.removeEventListener('pagehide', handlePageHide);
            // Flush any remaining unsaved mark when navigating inside the SPA
            flushPositionUpdate();
        };
    }, [bookId, flushPositionUpdate]);

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
    }, [getChapFromCfi]);

    /**
     * Search a string inside the book
     * @param value the string to search
     * @param all true to search inside all the book, false to search only inside the current chapter
     * @returns {Promise<*[]>}
     */
    const search = React.useCallback((value, all) => {
        if (!book.current) {
            return Promise.resolve([]);
        }
        if (all) {
            return Promise.all(book.current.spine.spineItems.map(item => searchSpine(item, value)))
                .then(results => Promise.resolve([].concat.apply([], results)));
        }
        const currentCfi = renditionRef.current?.location?.start?.cfi;
        if (!currentCfi) {
            return Promise.resolve([]);
        }
        const item = book.current.spine.get(currentCfi);
        return item ? searchSpine(item, value) : Promise.resolve([]);
    }, [searchSpine]);

    /**
     * Navigate to location
     * @param href {string} book location
     */
    const navigateTo = React.useCallback((href) => {
        if (!renditionRef.current) {
            return;
        }
        renditionRef.current.display(href).then(res => console.debug("Navigate", href));
    }, []);

    return (
        <BookContext value={{
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
            <BookBody loading={loading} viewerRef={viewerRef}/>
            <BookFooter/>
            <ImageViewModal
                open={imageModal.open}
                src={imageModal.src}
                alt={imageModal.alt}
                onClose={() => setImageModal({ open: false, src: '', alt: '' })}
            />
        </BookContext>
    );
}
