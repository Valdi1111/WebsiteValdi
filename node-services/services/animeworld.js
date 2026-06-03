const { chromium } = require('playwright');

let browser;

// avvia browser UNA volta sola
async function getBrowser() {
    if (!browser) {
        browser = await chromium.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox'
            ]
        });
    }
    return browser;
}

async function extractMp4(url) {
    const browser = await getBrowser();
    const page = await browser.newPage();

    try {
        await page.goto(url, {
            waitUntil: 'domcontentloaded',
            timeout: 60000
        });

        // attesa render JS + player
        await page.waitForFunction(() => {
            // controllo esistenza elemento
            const v = document.querySelector('#player video source')?.src
            return v && v.endsWith('.mp4');
        }, { timeout: 60000 });

        // estrazione video
        return await page.evaluate(() => {
            const source = document.querySelector('#player video source');
            return source?.src || null;
        });
    } finally {
        await page.close();
    }
    return null;
}

module.exports = {
    extractMp4
};