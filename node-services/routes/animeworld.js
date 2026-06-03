const express = require('express');
const { extractMp4 } = require('../services/animeworld');

const router = express.Router();

// GET /extract-url
router.get('/extract-url', async (req, res) => {
    const url = req.query.url;

    if (!url) {
        return res.status(400).json({
            ok: false,
            error: 'missing url'
        });
    }

    try {
        const queue = req.app.locals.queue;
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

module.exports = router;