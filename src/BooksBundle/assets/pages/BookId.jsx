import BookHeader from "@BooksBundle/components/books/BookHeader";
import BookFooter from "@BooksBundle/components/books/BookFooter";
import BookBody from "@BooksBundle/components/books/BookBody";
import BookContext from "@BooksBundle/components/books/BookContext";
import { useThemes } from "@CoreBundle/components/theme/ThemeContext";
import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { useParams } from "react-router";
import React from "react";
// import "@BooksBundle/styles/iframe.css";
import {
    FORCE_TEXT_COLOR, FONT, FONTS, FONT_SIZE,
    SPACING, GAP, WIDTH,
    FORCE_FONT, FORCE_FONT_SIZE, JUSTIFY,
    LAYOUT, LAYOUTS,
    UPDATE_LAST_READ
} from "@BooksBundle/components/books/BookConstants";
import { theme as antdTheme } from "antd";
import ImageViewModal from "@BooksBundle/components/books/modals/ImageViewModal.jsx";

// Import Foliate-js custom element definition
import { makeBook } from "@foliate/view.js";
import { makeHttpRangeReader } from "@BooksBundle/utils/httpRangeReader.js";

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

    // Unified book location (position, current/total pages and progress fraction)
    const [location, setLocation] = React.useState({
        position: null,
        current: 0,
        total: 100,
        fraction: 0
    });
    const locationRef = React.useRef(location);
    locationRef.current = location;

    // Book data
    const [title, setTitle] = React.useState('');
    const [navigation, setNavigation] = React.useState([]);
    const [chapter, setChapter] = React.useState(null);
    const [section, setSection] = React.useState(null);

    // Image modal
    const [imageModal, setImageModal] = React.useState({ open: false, src: '', alt: '' });

    // Track pending unsaved progress
    const pendingLocationRef = React.useRef(null);
    const settingsRef = React.useRef(settings);
    settingsRef.current = settings;

    const api = useBackendApi();
    const apiRef = React.useRef(api);
    apiRef.current = api;

    const { bookId } = useParams();

    // Track currently active section documents rendered by Foliate
    const activeDocsRef = React.useRef(new Set());

    /**
     * Go to the previous page
     */
    const prev = React.useCallback(() => {
        const view = renditionRef.current;
        if (!view) return;
        if (view.renderer?.prev) {
            view.renderer.prev();
        } else {
            view.prev();
        }
    }, []);

    /**
     * Go to the next page
     */
    const next = React.useCallback(() => {
        const view = renditionRef.current;
        if (!view) return;
        if (view.renderer?.next) {
            view.renderer.next();
        } else {
            view.next();
        }
    }, []);

    /**
     * Handle arrow right/left to navigate book pages
     * @param e event
     */
    const onKeyDown = React.useCallback((e) => {
        const code = e.keyCode || e.which;
        const key = e.key;

        if (code === 37 || key === 'ArrowLeft') {
            prev();
        }
        if (code === 39 || key === 'ArrowRight') {
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

    const updatePage = React.useCallback((detail) => {
        if (!detail || !book.current) {
            return;
        }
        const cfi = detail.cfi;
        const fraction = detail.fraction ?? 0;
        const loc = detail.location;

        // update current chapter
        if (detail.tocItem) {
            setChapter({ label: detail.tocItem.label, href: detail.tocItem.href, cfi: detail.cfi });
        } else {
            setChapter(null);
        }

        // update section
        const totalSections = book.current.sections ? book.current.sections.length : 1;
        setSection({
            current: detail.section ?? 0,
            total: totalSections
        });

        // update location (unifies current, total, position and fraction)
        setLocation({
            position: cfi,
            current: loc?.current ?? 0,
            total: loc?.total ?? 100,
            fraction: fraction
        });
    }, []);

    // Keep latest updatePage in a ref to avoid recreating event listeners
    const updatePageRef = React.useRef(updatePage);
    updatePageRef.current = updatePage;

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
        const textAlign = isJustify ? 'justify' : 'left';

        let css = `
            html, body {
                background-color: ${colorBgElevated} !important;
                background: ${colorBgElevated} !important;
                color: ${colorText} ${isForceText ? '!important' : ''};
                font-family: ${fontFamily} ${isForceFont ? '!important' : ''};
                font-size: ${fontSize}px ${isForceFontSize ? '!important' : ''};
                line-height: ${spacing} !important;
                text-align: ${textAlign} !important;
            }
            /* Explicitly apply text-align and line-height to elements to override book styling */
            p, div, li, span, h1, h2, h3, h4, h5, h6 {
                text-align: ${textAlign} !important;
                line-height: ${spacing} !important;
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
                p, span, div, li, h1, h2, h3, h4, h5, h6, blockquote, em, strong {
                    color: ${colorText} !important;
                }
            `;
        }

        if (isForceFont) {
            css += `
                p, span, div, li, h1, h2, h3, h4, h5, h6, blockquote, em, strong {
                    font-family: ${fontFamily} !important;
                }
            `;
        }

        if (isForceFontSize) {
            css += `
                p, span, div, li {
                    font-size: ${fontSize}px !important;
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
        } else {
            // Append to the end of head so that it always has highest cascade priority
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
        const view = renditionRef.current;
        if (!view) {
            return;
        }

        const cssString = getComputedCssString();

        // Update container background directly
        if (viewerRef.current) {
            viewerRef.current.style.backgroundColor = colorBgElevated;
        }

        // Apply styles to Foliate view renderer
        if (view.renderer?.setStyles) {
            view.renderer.setStyles(cssString);
        }

        // Live-update all actively mounted section documents in memory
        activeDocsRef.current.forEach(doc => {
            try {
                if (doc && doc.defaultView) {
                    injectStylesheetToDoc(doc, cssString);
                } else {
                    activeDocsRef.current.delete(doc);
                }
            } catch (e) {
                activeDocsRef.current.delete(doc);
            }
        });
    }, [getComputedCssString, colorBgElevated, injectStylesheetToDoc]);

    /**
     * Apply layout configuration to foliate renderer
     */
    const applyLayoutSettings = React.useCallback(() => {
        const view = renditionRef.current;
        if (!view?.renderer) {
            return;
        }

        const layoutKey = settingsRef.current[LAYOUT] || 'auto';
        const layoutConfig = LAYOUTS[layoutKey]?.settings || LAYOUTS.auto.settings;
        const maxColumnCount = layoutConfig.maxColumnCount ?? 2;
        const flow = layoutConfig.flow || 'paginated';

        const gapVal = settingsRef.current[GAP] !== undefined && settingsRef.current[GAP] !== null
            ? parseInt(settingsRef.current[GAP], 10)
            : 6;
        const gapPercent = isNaN(gapVal) ? 6 : Math.max(0, Math.min(gapVal, 30));
        const gapAttr = `${gapPercent}%`;

        const totalWidth = parseInt(settingsRef.current[WIDTH], 10) || 1400;

        // Constrain container and foliate-view elements
        if (viewerRef.current) {
            viewerRef.current.style.maxWidth = `${totalWidth}px`;
            viewerRef.current.style.width = '100%';
            viewerRef.current.style.height = '100%';
            viewerRef.current.style.margin = '0 auto';
        }
        view.style.maxWidth = `${totalWidth}px`;
        view.style.width = '100%';
        view.style.height = '100%';
        view.style.margin = '0 auto';

        const renderer = view.renderer;

        // Set flow, gap and column count attributes on Foliate renderer
        renderer.setAttribute('flow', flow);
        renderer.setAttribute('gap', gapAttr);
        renderer.setAttribute('max-column-count', String(maxColumnCount));

        // Compute column width (max-inline-size in foliate-js represents per-column width)
        if (flow === 'scrolled') {
            const scrollWidth = Math.min(totalWidth, 900);
            renderer.setAttribute('max-inline-size', `${scrollWidth}px`);
        } else if (maxColumnCount === 1) {
            const singleWidth = Math.min(totalWidth, 1000);
            renderer.setAttribute('max-inline-size', `${singleWidth}px`);
        } else {
            const availableContentWidth = totalWidth * (1 - (gapPercent / 100));
            const doubleColWidth = Math.floor(availableContentWidth / 2);
            renderer.setAttribute('max-inline-size', `${doubleColWidth}px`);
        }
    }, []);

    const applyLayoutSettingsRef = React.useRef(applyLayoutSettings);
    applyLayoutSettingsRef.current = applyLayoutSettings;

    /**
     * Load book
     */
    React.useEffect(() => {
        setLoading(true);
        let active = true;

        apiRef.current
            .withErrorHandling()
            .books()
            .getId(bookId)
            .then(async res => {
                if (!active) {
                    return;
                }
                console.debug("Loading book", bookId);
                setTitle(res.data.book_metadata.title);

                // Initialize location from backend progress
                setLocation(res.data.book_progress);

                const area = viewerRef.current;
                if (!area || isRendering.current) {
                    return;
                }
                isRendering.current = true;

                // Ensure container is clean
                area.innerHTML = '';

                const foliateView = document.createElement('foliate-view');
                // Ensure custom element is treated as block to correctly compute dimensions
                foliateView.style.display = 'block';
                foliateView.style.width = '100%';
                foliateView.style.height = '100%';
                area.appendChild(foliateView);

                renditionRef.current = foliateView;

                // Listen to relocate events using ref to decouple handler from dependency array
                foliateView.addEventListener('relocate', (e) => {
                    updatePageRef.current?.(e.detail);
                });

                // Automatically inject current dynamic style whenever new chapter content loads
                foliateView.addEventListener('load', (e) => {
                    const doc = e.detail?.doc;
                    if (doc) {
                        // Register active document to enable instant live style updates
                        activeDocsRef.current.add(doc);

                        let mouseTimer = null;

                        // Attach keyboard navigation directly to iframe document so focus inside book doesn't block arrows
                        doc.addEventListener('keydown', onKeyDown);

                        // Clean up when document is unloaded
                        if (doc.defaultView) {
                            doc.defaultView.addEventListener('unload', () => {
                                if (mouseTimer) window.clearTimeout(mouseTimer);
                                doc.removeEventListener('keydown', onKeyDown);
                                activeDocsRef.current.delete(doc);
                            }, { once: true });
                        }

                        injectStylesheetToDoc(doc, cssStringRef.current);

                        // Open image view modal when clicking on img or svg image inside chapter iframe
                        doc.addEventListener('click', evt => {
                            const target = evt.target;
                            const tag = target?.tagName ? target.tagName.toLowerCase() : '';

                            if (tag === 'img') {
                                evt.preventDefault();
                                setImageModal({
                                    open: true,
                                    src: target.currentSrc || target.src,
                                    alt: target.alt || 'Image from book'
                                });
                            } else if (tag === 'image') {
                                evt.preventDefault();
                                const href = target.getAttribute('xlink:href') || target.getAttribute('href');
                                if (href) {
                                    // Resolve relative SVG image path against iframe document location
                                    const resolvedSrc = new URL(href, doc.location.href).href;
                                    setImageModal({
                                        open: true,
                                        src: resolvedSrc,
                                        alt: 'Image from book'
                                    });
                                }
                            }
                        });

                        // Turn page on mouse wheel
                        if (settingsRef.current[LAYOUT] === 'auto' || settingsRef.current[LAYOUT] === 'single' || settingsRef.current[LAYOUT] === 'double') {
                            doc.documentElement.onwheel = wheelEvt => {
                                if (wheelEvt.deltaY < 0) {
                                    prev();
                                }
                                if (wheelEvt.deltaY > 0) {
                                    next();
                                }
                            };
                        }

                        // Turn page on touch swipe
                        let start, end;
                        doc.documentElement.ontouchstart = touchEvt => {
                            start = touchEvt.changedTouches[0];
                        };
                        doc.documentElement.ontouchend = touchEvt => {
                            end = touchEvt.changedTouches[0];
                            const boundArea = viewerRef.current;
                            if (boundArea && start && end) {
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

                        // Hide cursor after 3 seconds
                        let cursorVisible = true;
                        doc.documentElement.onmousemove = () => {
                            if (mouseTimer) {
                                window.clearTimeout(mouseTimer);
                            }
                            if (!cursorVisible) {
                                doc.documentElement.style.cursor = 'default';
                                cursorVisible = true;
                            }
                            mouseTimer = window.setTimeout(() => {
                                mouseTimer = null;
                                doc.documentElement.style.cursor = 'none';
                                cursorVisible = false;
                            }, 3000);
                        };
                    }
                });

                // Set container background
                area.style.backgroundColor = colorBgElevated;

                const downloadUrl = apiRef.current.books().epubUrl(res.data.id);

                // 1. Initialize HTTP range reader with 256 KB chunk cache
                const rangeReader = await makeHttpRangeReader(downloadUrl);

                // 2. Create book model
                const bookModel = await makeBook(rangeReader);
                book.current = bookModel;

                // 3. Pass opened book model to foliate-view
                await foliateView.open(bookModel);
                book.current = foliateView.book;

                // Extract navigation TOC if available from book structure
                if (foliateView.book?.toc) {
                    setNavigation(foliateView.book.toc);
                }

                // Apply initial layout settings
                applyLayoutSettingsRef.current();
                if (foliateView.renderer?.setStyles) {
                    foliateView.renderer.setStyles(cssStringRef.current);
                }

                // Display
                const currentPosition = res.data.book_progress?.position;
                if (!currentPosition) {
                    await foliateView.goTo(0);
                } else {
                    await foliateView.goTo(currentPosition);
                }

                isRendering.current = false;
                if (active) {
                    setLoading(false);
                }
            })
            .catch(err => {
                console.error("Failed to load book:", err);
                isRendering.current = false;
                if (active) {
                    setLoading(false);
                }
            });

        return () => {
            active = false;
            activeDocsRef.current.clear();
            if (renditionRef.current) {
                renditionRef.current.close?.();
                renditionRef.current.remove?.();
                renditionRef.current = null;
            }
            book.current = null;
            isRendering.current = false;
        };
    }, [bookId]); // Depend strictly on bookId to prevent infinite loop

    /**
     * Handle size, margin and layout updates dynamically
     */
    React.useEffect(() => {
        applyLayoutSettings();
    }, [settings[GAP], settings[WIDTH], settings[LAYOUT], applyLayoutSettings]);

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
        const pending = pendingLocationRef.current;
        if (!pending || !pending.position) {
            return;
        }

        const update = settingsRef.current[UPDATE_LAST_READ] === 'true';
        console.debug("Flushing position update to backend:", pending);

        apiRef.current
            .withErrorHandling()
            .books()
            .updatePosition(bookId, pending.position, pending.current, pending.total, pending.fraction, update)
            .then(() => {
                console.debug("Position flush completed!");
                // Clear pending location if it hasn't changed in the meantime
                if (pendingLocationRef.current === pending) {
                    pendingLocationRef.current = null;
                }
            })
            .catch(err => {
                console.error("Failed to flush position:", err);
            });
    }, [bookId]);

    /**
     * Debounced position update + guaranteed flush on unmount and page exit
     */
    React.useEffect(() => {
        if (!location.position || loading) {
            return;
        }

        // Record the latest location as pending
        pendingLocationRef.current = location;

        // Debounce update during active reading
        const timer = setTimeout(() => {
            flushPositionUpdate();
        }, 800);

        return () => {
            clearTimeout(timer);
        };
    }, [location, loading, flushPositionUpdate]);

    /**
     * Handle browser tab close, window reload or external navigation
     */
    React.useEffect(() => {
        const handlePageHide = () => {
            const pending = pendingLocationRef.current;
            if (!pending || !pending.position) {
                return;
            }

            const update = settingsRef.current[UPDATE_LAST_READ] === 'true';
            const payload = JSON.stringify({
                position: pending.position,
                current: pending.current,
                total: pending.total,
                fraction: pending.fraction,
                update: update,
            });

            // Get exact endpoint from BackendApi helper
            const endpoint = apiRef.current.books().updatePositionUrl(bookId);

            // sendBeacon does not support PUT, so fetch with keepalive: true is used to match axiosInstance.put
            fetch(endpoint, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: payload,
                keepalive: true,
            }).catch(() => {});
        };

        // Listen for browser close or navigation away from page
        window.addEventListener('pagehide', handlePageHide);

        return () => {
            window.removeEventListener('pagehide', handlePageHide);
            // Flush any remaining unsaved location when navigating inside the SPA
            flushPositionUpdate();
        };
    }, [bookId, flushPositionUpdate]);

    /**
     * Clear all active search highlights on Foliate
     */
    const clearSearchHighlights = React.useCallback(() => {
        const view = renditionRef.current;
        if (!view) {
            return;
        }

        view.clearSearch();
    }, []);

    /**
     * Search a string inside the book
     * @param value the string to search
     * @param all true to search inside all the book, false to search only inside the current chapter
     * @returns {Promise<*[]>}
     */
    const search = React.useCallback(async (value, all = true) => {
        const view = renditionRef.current;
        if (!view) {
            return [];
        }

        // When search value is empty, clear highlights and exit
        if (!value || !value.trim()) {
            clearSearchHighlights();
            return [];
        }

        // Clear previous highlights before starting a new search
        clearSearchHighlights();

        const query = value.trim();
        const results = [];

        try {
            const searchIterator = view.search({ query });

            if (!searchIterator) {
                return [];
            }

            for await (const result of searchIterator) {
                // Ignore progress chunks ({ progress: 0.04... })
                if (!result || !result.subitems || !Array.isArray(result.subitems)) {
                    continue;
                }

                const sectionLabel = result.label || null;

                for (const item of result.subitems) {
                    if (!item || !item.cfi) {
                        continue;
                    }

                    // Extract pre, match, post from the item.excerpt object
                    const pre = item.excerpt?.pre ?? '';
                    const match = item.excerpt?.match ?? query;
                    const post = item.excerpt?.post ?? '';

                    // Construct a single plain string for react-highlight-words
                    const excerptString = `${pre}${match}${post}`;

                    // Resolve chapter label from section label
                    const chapterLabel = sectionLabel || (chapter?.label ?? 'Match');

                    results.push({
                        cfi: item.cfi,
                        excerpt: excerptString || match,
                        chapter: { label: chapterLabel }
                    });

                    if (!all && results.length >= 50) {
                        break;
                    }
                }

                if (!all && results.length >= 50) {
                    break;
                }
            }
        } catch (err) {
            console.error("Search error in foliate-js:", err);
        }
        return results;
    }, [chapter, clearSearchHighlights]);

    /**
     * Navigate to location
     * @param href {string} book location
     */
    const navigateTo = React.useCallback((href) => {
        if (!renditionRef.current) {
            return;
        }
        renditionRef.current.goTo(href).then(() => console.debug("Navigate", href));
    }, []);

    return (
        <BookContext value={{
            title, setTitle,
            navigation, setNavigation,
            chapter, setChapter,
            section, setSection,
            location, setLocation,
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
