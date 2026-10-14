import BookHeader from "@BooksBundle/components/books/BookHeader";
import BookFooter from "@BooksBundle/components/books/BookFooter";
import BookBody from "@BooksBundle/components/books/BookBody";
import BookContext from "@BooksBundle/components/books/BookContext";
import SelectionFloatingMenu from "@BooksBundle/components/books/SelectionFloatingMenu";
import NoteModal from "@BooksBundle/components/books/modals/NoteModal.jsx";
import ImageViewModal from "@BooksBundle/components/books/modals/ImageViewModal.jsx";
import { useThemes } from "@CoreBundle/components/theme/ThemeContext";
import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { useParams } from "react-router";
import React from "react";
// import "@BooksBundle/styles/iframe.css";
import {
    FORCE_TEXT_COLOR, FONT, FONT_SIZE,
    SPACING, GAP, WIDTH,
    FORCE_FONT, FORCE_FONT_SIZE, JUSTIFY,
    LAYOUT, LAYOUTS,
    UPDATE_LAST_READ
} from "@BooksBundle/components/books/BookConstants";
import {theme as antdTheme, App} from "antd";

// Import Foliate-js custom element definition and Overlayer utilities
import { makeBook } from "@foliate/view.js";
import { Overlayer } from "@foliate/overlayer.js";
import { makeHttpRangeReader } from "@BooksBundle/utils/httpRangeReader.js";
import { drawBookmarkMarker, collapseRangeToFirstChar, getParagraphStartRange } from "@BooksBundle/utils/bookOverlayer.js";
import { buildBookThemeCss, injectStylesheetToDoc } from "@BooksBundle/utils/bookThemeCss.js";

export default function BookId() {
    const { token: { colorBgElevated, colorText, colorLink, colorLinkHover, colorPrimary } } = antdTheme.useToken();
    const [contentsDrawerOpen, setContentsDrawerOpen] = React.useState(false);
    const [settingsDrawerOpen, setSettingsDrawerOpen] = React.useState(false);
    const { message, modal } = App.useApp();
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
    const chapterRef = React.useRef(null);
    chapterRef.current = chapter;

    // Bookmarks and Notes state
    const [bookmarks, setBookmarks] = React.useState([]);
    const bookmarksRef = React.useRef([]);
    bookmarksRef.current = bookmarks;

    const [notes, setNotes] = React.useState([]);
    const notesRef = React.useRef([]);
    notesRef.current = notes;

    // Mode to allow user to click any paragraph in the book to place an exact bookmark
    const [bookmarkPickMode, setBookmarkPickMode] = React.useState(false);
    const bookmarkPickModeRef = React.useRef(false);
    bookmarkPickModeRef.current = bookmarkPickMode;

    // Floating selection menu state
    const [selectionMenu, setSelectionMenu] = React.useState({
        open: false,
        x: 0,
        y: 0,
        placement: 'top',
        cfi: '',
        text: '',
        sectionIndex: null,
        chapter: ''
    });

    // Note creation / editing modal state
    const [noteModal, setNoteModal] = React.useState({
        open: false,
        id: null,
        cfi: '',
        text: '',
        note: '',
        color: '#ffeb3b',
        chapter: '',
        sectionIndex: null
    });

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
     * Clear active browser text selections across all loaded chapter documents
     */
    const clearActiveSelections = React.useCallback(() => {
        activeDocsRef.current.forEach(doc => {
            try {
                doc.getSelection()?.removeAllRanges();
            } catch (e) {
                // Ignore unloaded docs
            }
        });
        setSelectionMenu(prev => prev.open ? { ...prev, open: false } : prev);
    }, []);

    /**
     * Draw or remove a note annotation on Foliate view
     */
    const renderNoteAnnotation = React.useCallback((noteItem, remove = false) => {
        const view = renditionRef.current;
        if (!view || !view.addAnnotation || !noteItem?.cfi) return;
        view.addAnnotation({
            value: noteItem.cfi,
            type: 'note',
            color: noteItem.color || '#ffeb3b',
            note: noteItem.note,
            id: noteItem.id
        }, remove);
    }, []);

    /**
     * Draw or remove a Word-style bookmark indicator on Foliate view
     */
    const renderBookmarkAnnotation = React.useCallback((bookmarkItem, remove = false) => {
        const view = renditionRef.current;
        if (!view || !view.addAnnotation || !bookmarkItem?.cfi) return;
        view.addAnnotation({
            value: bookmarkItem.cfi,
            type: 'bookmark',
            color: '#ff4d4f',
            label: bookmarkItem.label,
            id: bookmarkItem.id
        }, remove);
    }, []);

    /**
     * Add a bookmark at a specific CFI or fallback to current reading location
     */
    const addBookmark = React.useCallback((customLabel = null, customCfi = null) => {
        const targetCfi = customCfi || locationRef.current?.position;
        if (!targetCfi) return;

        const payload = {
            cfi: targetCfi,
            label: customLabel || chapterRef.current?.label || `Page ${locationRef.current?.current || 1}`,
            chapter: chapterRef.current?.label || null,
            fraction: locationRef.current?.fraction || 0
        };

        apiRef.current
            .withErrorHandling()
            .books()
            .addBookmark(bookId, payload)
            .then(res => {
                console.debug("Bookmark created:", res.data);
                setBookmarks(prevList => [res.data, ...prevList]);
                renderBookmarkAnnotation(res.data, false);
                message.success("Bookmark saved");
            });
    }, [bookId, message, renderBookmarkAnnotation]);

    const addBookmarkRef = React.useRef(addBookmark);
    addBookmarkRef.current = addBookmark;

    /**
     * Delete an existing bookmark
     */
    const deleteBookmark = React.useCallback((bookmarkId) => {
        const target = bookmarksRef.current.find(b => b.id === bookmarkId);
        apiRef.current
            .withErrorHandling()
            .books()
            .deleteBookmark(bookId, bookmarkId)
            .then(() => {
                console.debug("Bookmark deleted:", bookmarkId);
                if (target) {
                    renderBookmarkAnnotation(target, true);
                }
                setBookmarks(prevList => prevList.filter(item => item.id !== bookmarkId));
                message.success("Bookmark deleted");
            });
    }, [bookId, message, renderBookmarkAnnotation]);

    const deleteBookmarkRef = React.useRef(deleteBookmark);
    deleteBookmarkRef.current = deleteBookmark;

    /**
     * Create a 1-tap quick highlight directly from the floating selection toolbar
     */
    const quickHighlight = React.useCallback((color) => {
        if (!selectionMenu.cfi) return;

        apiRef.current
            .withErrorHandling()
            .books()
            .addNote(bookId, {
                cfi: selectionMenu.cfi,
                text: selectionMenu.text,
                note: '',
                color: color,
                chapter: selectionMenu.chapter,
                sectionIndex: selectionMenu.sectionIndex
            })
            .then(res => {
                console.debug("Quick highlight created:", res.data);
                setNotes(prevList => [res.data, ...prevList]);
                renderNoteAnnotation(res.data, false);
                clearActiveSelections();
                message.success("Highlight saved");
            });
    }, [bookId, selectionMenu, renderNoteAnnotation, clearActiveSelections, message]);

    /**
     * Open the full note modal from the current text selection
     */
    const openNoteModalFromSelection = React.useCallback(() => {
        setNoteModal({
            open: true,
            id: null,
            cfi: selectionMenu.cfi,
            text: selectionMenu.text,
            note: '',
            color: '#ffeb3b',
            chapter: selectionMenu.chapter,
            sectionIndex: selectionMenu.sectionIndex
        });
        clearActiveSelections();
    }, [selectionMenu, clearActiveSelections]);

    /**
     * Save (create or update) a note from the modal
     */
    const saveNoteModal = React.useCallback(() => {
        if (noteModal.id) {
            apiRef.current
                .withErrorHandling()
                .books()
                .editNote(bookId, noteModal.id, {
                    note: noteModal.note,
                    color: noteModal.color
                })
                .then(res => {
                    console.debug("Note updated:", res.data);
                    setNotes(prevList => prevList.map(n => n.id === res.data.id ? res.data : n));
                    renderNoteAnnotation(res.data, false);
                    setNoteModal(prev => ({ ...prev, open: false }));
                    message.success("Note updated");
                });
        } else {
            apiRef.current
                .withErrorHandling()
                .books()
                .addNote(bookId, {
                    cfi: noteModal.cfi,
                    text: noteModal.text,
                    note: noteModal.note,
                    color: noteModal.color,
                    chapter: noteModal.chapter,
                    sectionIndex: noteModal.sectionIndex
                })
                .then(res => {
                    console.debug("Note created:", res.data);
                    setNotes(prevList => [res.data, ...prevList]);
                    renderNoteAnnotation(res.data, false);
                    setNoteModal(prev => ({ ...prev, open: false }));
                    message.success("Note saved");
                });
        }
    }, [bookId, noteModal, renderNoteAnnotation, message]);

    /**
     * Delete an existing note
     */
    const deleteNote = React.useCallback((noteItem) => {
        const target = typeof noteItem === 'object' ? noteItem : notesRef.current.find(n => n.id === noteItem);
        if (!target) return;

        apiRef.current
            .withErrorHandling()
            .books()
            .deleteNote(bookId, target.id)
            .then(() => {
                console.debug("Note deleted:", target.id);
                renderNoteAnnotation(target, true);
                setNotes(prevList => prevList.filter(n => n.id !== target.id));
                setNoteModal(prev => ({ ...prev, open: false }));
                message.success("Note deleted");
            });
    }, [bookId, renderNoteAnnotation, message]);

    /**
     * Go to the previous page
     */
    const prev = React.useCallback(() => {
        clearActiveSelections();
        const view = renditionRef.current;
        if (!view) return;
        if (view.renderer?.prev) {
            view.renderer.prev();
        } else {
            view.prev();
        }
    }, [clearActiveSelections]);

    /**
     * Go to the next page
     */
    const next = React.useCallback(() => {
        clearActiveSelections();
        const view = renditionRef.current;
        if (!view) return;
        if (view.renderer?.next) {
            view.renderer.next();
        } else {
            view.next();
        }
    }, [clearActiveSelections]);

    /**
     * Handle arrow right/left to navigate book pages
     * @param e event
     */
    const onKeyDown = React.useCallback((e) => {
        const activeTag = document.activeElement?.tagName?.toLowerCase();
        if (activeTag === 'input' || activeTag === 'textarea') {
            return;
        }

        const code = e.keyCode || e.which;
        const key = e.key;

        if (key === 'Escape') {
            if (bookmarkPickModeRef.current) {
                setBookmarkPickMode(false);
            }
            clearActiveSelections();
            return;
        }

        if (code === 37 || key === 'ArrowLeft') {
            prev();
        }
        if (code === 39 || key === 'ArrowRight') {
            next();
        }
    }, [prev, next, clearActiveSelections]);

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
        return buildBookThemeCss(settings, {
            colorBgElevated,
            colorText,
            colorLink,
            colorLinkHover,
            colorPrimary
        });
    }, [colorBgElevated, colorText, colorLink, colorLinkHover, colorPrimary, settings]);

    // Keep CSS string updated in a ref so newly loaded section hooks always use the latest
    const cssStringRef = React.useRef('');
    cssStringRef.current = getComputedCssString();

    // Toggle CSS class on active iframe documents when bookmarkPickMode changes
    React.useEffect(() => {
        activeDocsRef.current.forEach(doc => {
            if (doc?.body) {
                doc.body.classList.toggle('bookmark-pick-mode', bookmarkPickMode);
            }
        });
    }, [bookmarkPickMode]);

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
                    injectStylesheetToDoc(doc, cssString, colorBgElevated, bookmarkPickModeRef.current);
                } else {
                    activeDocsRef.current.delete(doc);
                }
            } catch (e) {
                activeDocsRef.current.delete(doc);
            }
        });
    }, [getComputedCssString, colorBgElevated]);

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
     * Load book, bookmarks and notes
     */
    React.useEffect(() => {
        setLoading(true);
        let active = true;

        Promise.all([
            apiRef.current.withErrorHandling().books().getId(bookId),
            apiRef.current.withErrorHandling().books().getBookmarks(bookId),
            apiRef.current.withErrorHandling().books().getNotes(bookId),
        ])
            .then(async ([res, bookmarksRes, notesRes]) => {
                if (!active) {
                    return;
                }
                console.debug("Loading book", bookId);
                setTitle(res.data.book_metadata.title);
                setBookmarks(bookmarksRes?.data || []);
                setNotes(notesRes?.data || []);

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

                // Render all currently loaded notes and bookmarks onto Foliate Overlayer
                const applyAllAnnotationsToView = () => {
                    notesRef.current.forEach(noteItem => {
                        if (noteItem?.cfi) {
                            foliateView.addAnnotation({
                                value: noteItem.cfi,
                                type: 'note',
                                color: noteItem.color || '#ffeb3b',
                                note: noteItem.note,
                                id: noteItem.id
                            });
                        }
                    });
                    bookmarksRef.current.forEach(bmItem => {
                        if (bmItem?.cfi) {
                            foliateView.addAnnotation({
                                value: bmItem.cfi,
                                type: 'bookmark',
                                color: '#ff4d4f',
                                label: bmItem.label,
                                id: bmItem.id
                            });
                        }
                    });
                };

                // Listen to relocate events using ref to decouple handler from dependency array
                foliateView.addEventListener('relocate', (e) => {
                    setSelectionMenu(prev => prev.open ? { ...prev, open: false } : prev);
                    updatePageRef.current?.(e.detail);
                });

                foliateView.addEventListener('create-overlayer', () => {
                    queueMicrotask(() => {
                        applyAllAnnotationsToView();
                    });
                });

                // Handle drawing highlights and bookmark icons on Foliate Overlayer
                foliateView.addEventListener('draw-annotation', (e) => {
                    const { draw, annotation, range, doc } = e.detail;
                    if (annotation.type === 'bookmark') {
                        // Collapse any range (full-page view, paragraph, or text selection) to its first visible char
                        if (range && doc) {
                            const startCharRange = collapseRangeToFirstChar(doc, range);
                            range.setStart(startCharRange.startContainer, startCharRange.startOffset);
                            range.setEnd(startCharRange.endContainer, startCharRange.endOffset);
                        }

                        draw(drawBookmarkMarker, {
                            color: annotation.color || '#ff4d4f',
                            onClick: () => {
                                const existingBookmark = bookmarksRef.current.find(
                                    b => b.id === annotation.id || b.cfi === annotation.value
                                );
                                if (existingBookmark) {
                                    modal.confirm({
                                        title: 'Bookmark',
                                        content: existingBookmark.label || existingBookmark.chapter || 'Saved bookmark',
                                        okText: 'Remove Bookmark',
                                        okButtonProps: { danger: true },
                                        cancelText: 'Close',
                                        onOk: () => deleteBookmarkRef.current(existingBookmark.id)
                                    });
                                }
                            }
                        });
                    } else {
                        draw(Overlayer.highlight, { color: annotation.color || '#ffeb3b' });
                    }
                });

                // Handle clicking an existing note highlight in the book (bookmarks are handled exclusively by their icon)
                foliateView.addEventListener('show-annotation', (e) => {
                    const { value } = e.detail;

                    const existingNote = notesRef.current.find(n => n.cfi === value);
                    if (existingNote) {
                        setNoteModal({
                            open: true,
                            id: existingNote.id,
                            cfi: existingNote.cfi,
                            text: existingNote.text || '',
                            note: existingNote.note || '',
                            color: existingNote.color || '#ffeb3b',
                            chapter: existingNote.chapter || '',
                            sectionIndex: existingNote.sectionIndex ?? existingNote.section_index ?? null
                        });
                    }
                });

                // Automatically inject current dynamic style whenever new chapter content loads
                foliateView.addEventListener('load', (e) => {
                    const doc = e.detail?.doc;
                    const index = e.detail?.index;
                    if (doc) {
                        // Register active document to enable instant live style updates
                        activeDocsRef.current.add(doc);

                        let mouseTimer = null;
                        let selectionTimer = null;
                        let isTouching = false;

                        // Attach keyboard navigation directly to iframe document so focus inside book doesn't block arrows
                        doc.addEventListener('keydown', onKeyDown);

                        // Clean up when document is unloaded
                        if (doc.defaultView) {
                            doc.defaultView.addEventListener('unload', () => {
                                if (mouseTimer) window.clearTimeout(mouseTimer);
                                if (selectionTimer) window.clearTimeout(selectionTimer);
                                doc.removeEventListener('keydown', onKeyDown);
                                activeDocsRef.current.delete(doc);
                            }, { once: true });
                        }

                        injectStylesheetToDoc(doc, cssStringRef.current, colorBgElevated, bookmarkPickModeRef.current);

                        setTimeout(() => {
                            applyAllAnnotationsToView();
                        }, 50);

                        const updateSelectionMenu = () => {
                            const selection = doc.getSelection();
                            if (!selection || selection.isCollapsed) {
                                setSelectionMenu(prev => prev.open ? { ...prev, open: false } : prev);
                                return;
                            }

                            const selectedText = selection.toString().trim();
                            if (!selectedText) {
                                setSelectionMenu(prev => prev.open ? { ...prev, open: false } : prev);
                                return;
                            }

                            const range = selection.getRangeAt(0);
                            const rect = range.getBoundingClientRect();
                            if (rect.width === 0 && rect.height === 0) return;

                            const frameElement = doc.defaultView?.frameElement;
                            const frameRect = frameElement
                                ? frameElement.getBoundingClientRect()
                                : { left: 0, top: 0 };

                            const absX = frameRect.left + rect.left + (rect.width / 2);
                            const isMobile = window.matchMedia('(pointer: coarse)').matches;
                            const placeBottom = isMobile || (frameRect.top + rect.top < 90);
                            const absY = placeBottom
                                ? frameRect.top + rect.bottom + 12
                                : frameRect.top + rect.top - 12;

                            const cfi = foliateView.getCFI(index, range);

                            setSelectionMenu({
                                open: true,
                                x: Math.max(150, Math.min(window.innerWidth - 150, absX)),
                                y: Math.max(16, Math.min(window.innerHeight - 60, absY)),
                                placement: placeBottom ? 'bottom' : 'top',
                                cfi: cfi,
                                text: selectedText,
                                sectionIndex: index,
                                chapter: chapterRef.current?.label || null
                            });
                        };

                        doc.addEventListener('selectionchange', () => {
                            if (selectionTimer) window.clearTimeout(selectionTimer);
                            selectionTimer = window.setTimeout(() => {
                                if (!isTouching) {
                                    updateSelectionMenu();
                                }
                            }, 250);
                        });

                        doc.addEventListener('mouseup', () => {
                            setTimeout(updateSelectionMenu, 40);
                        });

                        // Open image view modal when clicking on img or svg image inside chapter iframe
                        // Handle paragraph bookmark pick mode
                        doc.addEventListener('click', evt => {
                            const target = evt.target;
                            const tag = target?.tagName ? target.tagName.toLowerCase() : '';

                            // Check if user is picking a specific paragraph for a bookmark (or holding Alt+Click)
                            if (bookmarkPickModeRef.current || evt.altKey) {
                                const blockNode = target?.closest?.('p, li, h1, h2, h3, h4, h5, h6, blockquote');
                                if (blockNode) {
                                    evt.preventDefault();
                                    evt.stopPropagation();

                                    // Always target the exact beginning of the paragraph's text
                                    const startCharRange = getParagraphStartRange(doc, blockNode);
                                    const cfi = foliateView.getCFI(index, startCharRange);

                                    const rawText = blockNode.textContent?.trim() || '';
                                    const snippet = rawText
                                        ? `«${rawText.slice(0, 65)}${rawText.length > 65 ? '…' : ''}»`
                                        : null;

                                    addBookmarkRef.current(snippet, cfi);
                                    setBookmarkPickMode(false);
                                    return;
                                }
                            }

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

                        // Turn page on touch swipe (guarded against active text selection on mobile)
                        let start, end;
                        doc.documentElement.ontouchstart = touchEvt => {
                            isTouching = true;
                            start = touchEvt.changedTouches[0];
                        };
                        doc.documentElement.ontouchend = touchEvt => {
                            isTouching = false;

                            const selection = doc.getSelection();
                            if (selection && !selection.isCollapsed && selection.toString().trim().length > 0) {
                                updateSelectionMenu();
                                return;
                            }

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

                applyAllAnnotationsToView();

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
            bookmarks, addBookmark, deleteBookmark,
            bookmarkPickMode, setBookmarkPickMode,
            notes, deleteNote, setNoteModal,
        }}>
            <title>{title}</title>
            <BookHeader/>

            <SelectionFloatingMenu
                selectionMenu={selectionMenu}
                bookmarkPickMode={bookmarkPickMode}
                setBookmarkPickMode={setBookmarkPickMode}
                onQuickHighlight={quickHighlight}
                onOpenNoteModal={openNoteModalFromSelection}
                onBookmarkSelection={() => {
                    const snippet = `«${selectionMenu.text.slice(0, 65)}${selectionMenu.text.length > 65 ? '…' : ''}»`;
                    addBookmark(snippet, selectionMenu.cfi);
                    clearActiveSelections();
                }}
                onCopySelection={() => {
                    navigator.clipboard.writeText(selectionMenu.text).then(() => {
                        message.success("Copied to clipboard");
                    });
                    clearActiveSelections();
                }}
                onTranslateSelection={() => {
                    const url = `https://translate.google.com/?sl=auto&tl=it&text=${encodeURIComponent(selectionMenu.text)}&op=translate`;
                    window.open(url, '_blank', 'noopener,noreferrer');
                    clearActiveSelections();
                }}
                onCloseMenu={clearActiveSelections}
            />

            <BookBody loading={loading} viewerRef={viewerRef}/>
            <BookFooter/>

            <ImageViewModal
                open={imageModal.open}
                src={imageModal.src}
                alt={imageModal.alt}
                onClose={() => setImageModal({ open: false, src: '', alt: '' })}
            />

            <NoteModal
                noteModal={noteModal}
                setNoteModal={setNoteModal}
                onSave={saveNoteModal}
                onDelete={deleteNote}
            />
        </BookContext>
    );
}
