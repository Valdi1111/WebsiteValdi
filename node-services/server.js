require('dotenv').config(); // .env
require('dotenv').config({ path: '.env.local', override: true }); // override locale

const express = require('express');
const PQueue = require('p-queue').default;

const animeworldRoutes = require('./routes/animeworld');
const renderRoutes = require('./routes/render');

const app = express();

const PORT = process.env.PORT || 3000;
// Loopback only by default: the routes drive a browser (and /render renders any HTML), never expose them
const HOST = process.env.LISTEN_HOST || '127.0.0.1';
const CONCURRENCY = parseInt(process.env.CONCURRENCY || "1", 10);
const RENDER_CONCURRENCY = parseInt(process.env.RENDER_CONCURRENCY || "2", 10);

// queue condivisa
const queue = new PQueue({
    concurrency: CONCURRENCY
});

// middleware per condividere queue nelle route
app.locals.queue = queue;
// Image renders get a queue of their own, so they never wait behind a scraping job
app.locals.renderQueue = new PQueue({
    concurrency: RENDER_CONCURRENCY
});

// mount route
app.use('/animeworld', animeworldRoutes);
app.use('/render', renderRoutes);

app.get('/health', (req, res) => {
    res.json({ ok: true });
});

// start server
app.listen(PORT, HOST, () => {
    console.log(`🚀 Playwright service running on http://${HOST}:${PORT}`);
});