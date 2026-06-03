require('dotenv').config(); // .env
require('dotenv').config({ path: '.env.local', override: true }); // override locale

const express = require('express');
const PQueue = require('p-queue').default;

const animeworldRoutes = require('./routes/animeworld');

const app = express();

const PORT = process.env.PORT || 3000;
const CONCURRENCY = parseInt(process.env.CONCURRENCY || "1", 10);

// queue condivisa
const queue = new PQueue({
    concurrency: CONCURRENCY
});

// middleware per condividere queue nelle route
app.locals.queue = queue;

// mount route
app.use('/animeworld', animeworldRoutes);

app.get('/health', (req, res) => {
    res.json({ ok: true });
});

// start server
app.listen(PORT, () => {
    console.log(`🚀 Playwright service running on http://localhost:${PORT}`);
});