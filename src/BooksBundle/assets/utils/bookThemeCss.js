import {
    FORCE_TEXT_COLOR,
    FONT,
    FONTS,
    FONT_SIZE,
    SPACING,
    FORCE_FONT,
    FORCE_FONT_SIZE,
    JUSTIFY
} from "@BooksBundle/components/books/BookConstants";

/**
 * Build raw CSS string for book theme to ensure clean overrides
 */
export function buildBookThemeCss(settings, tokens) {
    const { colorBgElevated, colorText, colorLink, colorLinkHover, colorPrimary } = tokens;

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
        /* Visual hover feedback when user is in paragraph bookmark pick mode */
        body.bookmark-pick-mode p:hover,
        body.bookmark-pick-mode li:hover,
        body.bookmark-pick-mode h1:hover,
        body.bookmark-pick-mode h2:hover,
        body.bookmark-pick-mode h3:hover,
        body.bookmark-pick-mode blockquote:hover {
            outline: 2px dashed ${colorPrimary} !important;
            outline-offset: 2px !important;
            cursor: crosshair !important;
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
}

/**
 * Helper to inject or completely replace custom theme stylesheet in a chapter document
 */
export function injectStylesheetToDoc(doc, cssString, colorBgElevated, isPickMode = false) {
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
        doc.body.classList.toggle('bookmark-pick-mode', isPickMode);
    }
}
