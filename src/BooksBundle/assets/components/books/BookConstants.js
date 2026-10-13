// Settings constants
export const FORCE_TEXT_COLOR = 'force-text-color';
export const FONT = 'font';
export const FORCE_FONT = 'force-font';
export const SPACING = 'spacing';
/**
 * Used as gap between columns/pages in Foliate
 * @type {string}
 */
export const GAP = 'gap';
export const WIDTH = 'width';
export const FONT_SIZE = 'font-size';
export const FORCE_FONT_SIZE = 'force-font-size';
export const JUSTIFY = 'justify';
export const LAYOUT = 'layout';
export const UPDATE_LAST_READ = 'update-last-read';

export const FONTS = {
    serif: 'Serif',
    sans_serif: 'Sans-serif',
    calibri: 'Calibri',
    monospace: 'Monospace',
    system_ui: 'System-ui'
};

export const FONT_SIZES = range(6, 30);

function range(start, end) {
    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
}

export const LAYOUTS = {
    auto: {
        name: 'Automatic (1 or 2 cols)',
        settings: { flow: 'paginated', maxColumnCount: 2 }
    },
    single: {
        name: 'Single column',
        settings: { flow: 'paginated', maxColumnCount: 1 }
    },
    double: {
        name: 'Double column',
        settings: { flow: 'paginated', maxColumnCount: 2 }
    },
    scrolled: {
        name: 'Continuous scroll',
        settings: { flow: 'scrolled', maxColumnCount: 1 }
    }
};
