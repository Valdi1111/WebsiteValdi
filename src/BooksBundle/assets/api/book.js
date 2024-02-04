import {API_URL} from "../constants";
import { Book } from "epubjs";
import axios from "axios";

export const EPUB_URL = `${API_URL}/epub`;

const locale = 'en-US';
const opts = {
    style: 'percent',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
};
const formatter = new Intl.NumberFormat(locale, opts);

export function formatReadPercent(page, total) {
    return formatter.format((!page || !total) ? 0 : (page / total));
}

export async function markUnread(id) {
    return axios.put(
        `${API_URL}/books/${id}/mark-unread`,
        {},
        {}
    );
}

export async function markRead(id) {
    return axios.put(
        `${API_URL}/books/${id}/mark-read`,
        {},
        {}
    );
}

export async function updatePosition(id, position, page, update = true) {
    return axios.put(
        `${API_URL}/books/${id}/position`,
        { position, page, update },
        {}
    );
}

export async function getMetadata(id) {
    return axios.get(
        `${API_URL}/books/${id}/metadata`,
        {}
    );
}

export async function getBook(id) {
    return axios.get(
        `${API_URL}/books/${id}`,
        {}
    );
}

export async function deleteBook(id) {
    return axios.delete(
        `${API_URL}/books/${id}`,
        {}
    );
}

export async function createBook(url) {
    console.log("Creating book from link...");
    const epub = new Book(EPUB_URL + url);
    const book = await epub.opened;
    try {
        // Generate Cache
        const { locations, navigation, cover } = await generateCache(book);
        // Save
        const res = await axios.post(
            `${API_URL}/books`,
            { url, locations, navigation, metadata: book.packaging.metadata },
            {}
        );
        if (cover) {
            const data = new FormData();
            data.append('cover', cover, 'cover.png');
            await axios.post(
                `${API_URL}/books/${res.data.id}/cover`,
                data,
                { headers: { 'Content-Type': 'multipart/form-data' } }
            );
        }
        console.log("Book", res.data.id, "Created successfully!");
        return Promise.resolve(res.data);
    } catch (e) {
        return Promise.reject(e);
    }
}

export async function recreateBookCache(url, id) {
    console.log("Book", id, "Recreating cache...");
    const epub = new Book(EPUB_URL + url);
    const book = await epub.opened;
    try {
        // Generate Cache
        const { locations, navigation, cover } = await generateCache(book, id);
        // Save
        const res = await axios.put(
            `${API_URL}/books/${id}`,
            { locations, navigation, metadata: book.packaging.metadata },
            {}
        );
        if (cover) {
            const data = new FormData();
            data.append('cover', cover, 'cover.png');
            await axios.post(
                `${API_URL}/books/${id}/cover`,
                data,
                { headers: { 'Content-Type': 'multipart/form-data' } }
            );
        } else {
            await axios.delete(`${API_URL}/books/${id}/cover`);
        }
        return Promise.resolve(res.data);
    } catch (e) {
        return Promise.reject(e);
    }
}

async function generateCache(book, id = '') {
    // Locations
    console.debug("Book", id, "Generating locations...");
    const locations = await book.locations.generate(1024);
    // Navigation
    console.debug("Book", id, "Generating navigation...");
    await loadAllSpines(book);
    const navigation = generateNavigation(book, book.navigation);
    // Cover
    console.debug("Book", id, "Generating cover...");
    const cover = await book.coverUrl();
    if (!cover) {
        console.error("Book", id, "Cover not found! Skipping cover caching...");
        return { locations, navigation };
    } else {
        const res = await fetch(cover);
        const blob = await res.blob();
        return { locations, navigation, cover: blob };
    }
}

async function loadAllSpines(book) {
    let spines = [];
    book.spine.each(s => {
        spines = [...spines, s];
    });
    await Promise.all(spines.map(async section => {
        await section.load(book.load.bind(book));
    }))
}

function generateNavigation(book, items) {
    let navigation = [];
    items.forEach(item => {
        let nav = {};
        nav.id = item.id;
        nav.label = item.label.trim();
        // check for ids in href
        let dash = "";
        if (item.href.includes("#")) {
            dash = "#" + item.href.split('#').pop();
        }
        // handle items with null href (section for chapters)
        if (!item.href) {
            nav.href = null;
        }
        // get href from spine
        else if (book.spine.get(item.href) !== null) {
            nav.href = book.spine.get(item.href).href + dash;
            //console.log("Using first method", nav["href"])
        }
        // try rebuilding the href and get from spine
        else {
            nav.href = book.spine.get("Text/" + item.href.split('/').pop()).href + dash;
            //console.log("Using second method", nav["href"])
        }
        // ignoring cfi for section
        if (!nav.href) {
            nav.cfi = null;
        }
        // get chapter cfi from spine
        else {
            const section = book.spine.get(nav.href);
            if (!dash) {
                nav.cfi = `epubcfi(${section.cfiBase}!/4/1:0)`;
            } else {
                nav.cfi = section.cfiFromElement(section.document.documentElement.querySelector(`[id='${dash}']`));
            }
        }
        // handle sub items
        nav.subitems = generateNavigation(book, item.subitems);
        navigation = [...navigation, nav];
    });
    return navigation;
}
