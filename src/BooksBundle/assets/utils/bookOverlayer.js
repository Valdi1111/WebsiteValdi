const SVG_NS = 'http://www.w3.org/2000/svg';

export const HIGHLIGHT_COLORS = [
    { label: 'Yellow', value: '#ffeb3b' },
    { label: 'Green', value: '#a5d6a7' },
    { label: 'Blue', value: '#90caf9' },
    { label: 'Pink', value: '#f48fb1' },
    { label: 'Orange', value: '#ffcc80' }
];

/**
 * Normalizes any DOM Range (whole page, paragraph element, or text selection)
 * into a 1-character Range positioned at the very first visible character.
 *
 * @param {Document} doc
 * @param {Range} range
 * @returns {Range}
 */
export function collapseRangeToFirstChar(doc, range) {
    if (!doc || !range) return range;

    const charRange = doc.createRange();
    let startNode = range.startContainer;
    let startOffset = range.startOffset;

    // If the range starts on an Element node (e.g. <p>), descend to its first non-empty text node
    if (startNode.nodeType === Node.ELEMENT_NODE) {
        const child = startNode.childNodes[startOffset] || startNode;
        const walker = doc.createTreeWalker(child, NodeFilter.SHOW_TEXT, {
            acceptNode: (node) => node.textContent.trim().length > 0
                ? NodeFilter.FILTER_ACCEPT
                : NodeFilter.FILTER_SKIP
        });
        const firstText = walker.nextNode();
        if (firstText) {
            const firstNonSpace = firstText.textContent.search(/\S/);
            const offset = firstNonSpace >= 0 ? firstNonSpace : 0;
            charRange.setStart(firstText, offset);
            charRange.setEnd(firstText, Math.min(firstText.textContent.length, offset + 1));
            return charRange;
        }
    }

    // If the range already starts inside a Text node, skip leading whitespace and select 1 char
    if (startNode.nodeType === Node.TEXT_NODE) {
        const text = startNode.textContent || '';
        const slice = text.slice(startOffset);
        const firstNonSpaceOffset = slice.search(/\S/);
        const offset = firstNonSpaceOffset >= 0 ? startOffset + firstNonSpaceOffset : startOffset;

        if (offset < text.length) {
            charRange.setStart(startNode, offset);
            charRange.setEnd(startNode, Math.min(text.length, offset + 1));
            return charRange;
        }
    }

    // Fallback: collapse original range to start
    charRange.setStart(range.startContainer, range.startOffset);
    charRange.collapse(true);
    return charRange;
}

/**
 * Draws a single clickable bookmark ribbon icon right at the start of the bookmarked position.
 * Fallbacks to top-left coordinates if rects are empty.
 *
 * @param {DOMRectList|DOMRect[]} rects
 * @param {Object} options
 * @returns {SVGGElement}
 */
export function drawBookmarkMarker(rects, options = {}) {
    const color = options.color || '#ff4d4f';
    const g = document.createElementNS(SVG_NS, 'g');

    // Default fallback coordinates if rects collection is empty
    let x = 15;
    let y = 15;

    if (rects && rects.length > 0) {
        const first = Array.from(rects).find(r => r.width > 0 || r.height > 0) || rects[0];
        if (first && (first.left || first.top)) {
            x = Math.max(2, first.left - 11);
            y = Math.max(2, first.top - 4);
        }
    }

    // Invisible larger hit-area rect so the small icon is easy to tap on mobile and desktop
    const hitArea = document.createElementNS(SVG_NS, 'rect');
    hitArea.setAttribute('x', String(x - 3));
    hitArea.setAttribute('y', String(y - 2));
    hitArea.setAttribute('width', '16');
    hitArea.setAttribute('height', '20');
    hitArea.setAttribute('fill', 'transparent');
    hitArea.style.pointerEvents = 'auto';
    hitArea.style.cursor = 'pointer';

    // Bookmark ribbon SVG shape (10x15 with V-notch)
    const ribbon = document.createElementNS(SVG_NS, 'path');
    const dRibbon = `M ${x} ${y} h 10 v 15 l -5 -3.5 l -5 3.5 Z`;
    ribbon.setAttribute('d', dRibbon);
    ribbon.setAttribute('fill', color);
    ribbon.setAttribute('fill-opacity', '0.9');
    ribbon.setAttribute('stroke', '#ffffff');
    ribbon.setAttribute('stroke-width', '0.8');
    ribbon.style.pointerEvents = 'auto';
    ribbon.style.cursor = 'pointer';

    const handleClick = (evt) => {
        evt.preventDefault();
        evt.stopPropagation();
        if (typeof options.onClick === 'function') {
            options.onClick();
        }
    };

    hitArea.addEventListener('click', handleClick);
    ribbon.addEventListener('click', handleClick);
    hitArea.addEventListener('touchend', (evt) => evt.stopPropagation());
    ribbon.addEventListener('touchend', (evt) => evt.stopPropagation());

    g.appendChild(hitArea);
    g.appendChild(ribbon);

    return g;
}

/**
 * Finds and returns a 1-character Range positioned strictly at the very beginning
 * of the actual text inside a paragraph or block node.
 *
 * @param {Document} doc
 * @param {HTMLElement} blockNode
 * @returns {Range}
 */
export function getParagraphStartRange(doc, blockNode) {
    const charRange = doc.createRange();
    if (!doc || !blockNode) {
        charRange.setStart(blockNode, 0);
        charRange.collapse(true);
        return charRange;
    }

    // Use a TreeWalker to find the first non-empty text node inside this block
    const walker = doc.createTreeWalker(blockNode, NodeFilter.SHOW_TEXT, {
        acceptNode: (node) => {
            if (node.textContent && node.textContent.trim().length > 0) {
                return NodeFilter.FILTER_ACCEPT;
            }
            return NodeFilter.FILTER_SKIP;
        }
    });

    const firstTextNode = walker.nextNode();
    if (firstTextNode) {
        const text = firstTextNode.textContent;
        const nonSpaceIdx = text.search(/\S/);
        const offset = nonSpaceIdx >= 0 ? nonSpaceIdx : 0;
        charRange.setStart(firstTextNode, offset);
        charRange.setEnd(firstTextNode, Math.min(text.length, offset + 1));
        return charRange;
    }

    // Fallback if no text node is found inside the block
    charRange.setStart(blockNode, 0);
    charRange.collapse(true);
    return charRange;
}
