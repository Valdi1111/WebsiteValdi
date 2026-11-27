const express = require('express');
const { renderPng } = require('../services/render');

const router = express.Router();

// The documents carry their images inline (base64), well above the default 100 KB body limit
router.use(express.json({ limit: '20mb' }));

function intInRange(value, fallback, min, max) {
    if (value === undefined || value === null) {
        return fallback;
    }
    const number = Number(value);
    return Number.isInteger(number) && number >= min && number <= max ? number : null;
}

// POST /render/png {html, width?, scale?, selector?} -> image/png of the selected element
router.post('/png', async (req, res) => {
    const { html, selector = '#card' } = req.body || {};
    const width = intInRange(req.body?.width, 1200, 200, 2400);
    const scale = intInRange(req.body?.scale, 2, 1, 3);

    if (typeof html !== 'string' || html === '' || typeof selector !== 'string' || width === null || scale === null) {
        return res.status(400).json({
            ok: false,
            error: 'invalid html, selector, width or scale'
        });
    }

    try {
        const queue = req.app.locals.renderQueue;
        const png = await queue.add(() => renderPng({ html, width, scale, selector }));
        res.type('png').send(png);
    } catch (err) {
        res.status(500).json({
            ok: false,
            error: err.message
        });
    }
});

module.exports = router;
