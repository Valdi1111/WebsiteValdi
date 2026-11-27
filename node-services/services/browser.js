const { chromium } = require('playwright');

let browserPromise = null;

/**
 * Headless Chromium shared by every service, launched once on first use (each caller opens its own page or context).
 * A browser that crashed or was closed is launched again on the next call.
 */
async function getBrowser() {
    if (!browserPromise) {
        browserPromise = chromium.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox'
            ]
        }).then((browser) => {
            browser.on('disconnected', () => {
                browserPromise = null;
            });
            return browser;
        }).catch((err) => {
            browserPromise = null;
            throw err;
        });
    }
    return browserPromise;
}

module.exports = {
    getBrowser
};
