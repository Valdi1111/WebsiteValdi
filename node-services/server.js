require('dotenv').config(); // .env
require('dotenv').config({ path: '.env.local', override: true }); // override locale

const express = require('express');
const { chromium } = require('playwright');
const PQueue = require('p-queue').default;

const app = express();

const PORT = process.env.PORT || 3000;
const CONCURRENCY = parseInt(process.env.CONCURRENCY || "1", 10);

// 🔥 QUEUE: evita overload (1 request alla volta)
const queue = new PQueue({ concurrency: CONCURRENCY });

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

// estrazione MP4
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

// API
app.get('/animeworld/extract-url', async (req, res) => {

    const url = req.query.url;

    if (!url) {
        return res.status(400).json({
            ok: false,
            error: 'missing url'
        });
    }

    try {
        const mp4 = await queue.add(() => extractMp4(url));

        res.json({
            ok: true,
            url: mp4
        });

    } catch (err) {
        res.status(500).json({
            ok: false,
            error: err.message
        });
    }
});

app.get('/health', (req, res) => {
    res.json({ ok: true });
});

// start server
app.listen(PORT, () => {
    console.log(`🚀 Playwright service running on http://localhost:${PORT}`);
});