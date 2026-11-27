const { getBrowser } = require('./browser');

const VIEWPORT_HEIGHT = 800;
const TIMEOUT_MS = 30000;

/**
 * Renders a self-contained HTML document and returns a PNG of the element matched by the selector.
 * The caller inlines everything (images and fonts as data URIs): scripts are disabled and every network request
 * is aborted, so the page can neither run code nor reach anything.
 *
 * @returns {Promise<Buffer>}
 */
async function renderPng({ html, width, scale, selector }) {
    const browser = await getBrowser();
    const context = await browser.newContext({
        viewport: { width, height: VIEWPORT_HEIGHT },
        deviceScaleFactor: scale,
        javaScriptEnabled: false,
    });

    try {
        await context.route('**/*', (route) => route.abort());
        const page = await context.newPage();
        await page.setContent(html, { waitUntil: 'load', timeout: TIMEOUT_MS });

        // The screenshot waits for the web fonts; the element may be taller than the viewport
        return await page.locator(selector).screenshot({ type: 'png', timeout: TIMEOUT_MS });
    } finally {
        await context.close();
    }
}

module.exports = {
    renderPng
};
