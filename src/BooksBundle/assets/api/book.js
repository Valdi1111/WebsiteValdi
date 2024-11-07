import axios from "@BooksBundle/api/axios";
import { Book } from "epubjs";

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

export function getEpubUrlByPath(url) {
    return axios.getUri({ url: `/epub${url}` });
}

export function getEpubUrlById(id) {
    return axios.getUri({ url: `/books/${id}/epub` });
}

export function getCoverUrl(id) {
    return axios.getUri({ url: `/books/${id}/cover` });
}

export async function markUnread(id) {
    return axios.put(`/books/${id}/mark-unread`);
}

export async function markRead(id) {
    return axios.put(`/books/${id}/mark-read`);
}

export async function updatePosition(id, position, page, update = true) {
    return axios.put(
        `/books/${id}/position`,
        { position, page, update },
    );
}

export async function getMetadata(id) {
    return axios.get(`/books/${id}/metadata`);
}

export async function getBook(id) {
    return axios.get(`/books/${id}`);
}

export async function deleteBook(id) {
    return axios.delete(`/books/${id}`);
}

export async function createBook(url) {
    console.log("Creating book from link...");
    const epub = new Book(getEpubUrlByPath(url));
    const book = await epub.opened;
    try {
        // Generate Cache
        const { locations, navigation } = await generateCache(book);
        // Save
        const res = await axios.post(
            `/books`,
            { url, book_cache: { locations, navigation }, book_metadata: generateMetadata(book.packaging.metadata) },
            {}
        );
        console.log("Book", res.data.id, "Created successfully!");
        return Promise.resolve(res.data);
    } catch (e) {
        return Promise.reject(e);
    }
}

export async function recreateBookCache(id) {
    console.log("Book", id, "Recreating cache...");
    const epub = new Book(getEpubUrlById(id), { openAs: 'epub' });
    const book = await epub.opened;
    try {
        // Generate Cache
        const { locations, navigation } = await generateCache(book, id);
        // Save
        const res = await axios.put(
            `/books/${id}`,
            { book_cache: { locations, navigation }, book_metadata: generateMetadata(book.packaging.metadata) },
            {}
        );
        return Promise.resolve(res.data);
    } catch (e) {
        return Promise.reject(e);
    }
}

function generateMetadata(metadata) {
    return {
        identifier: metadata.identifier,
        title: metadata.title,
        creator: metadata.creator,
        publisher: metadata.publisher,
        language: metadata.language,
        rights: metadata.rights,
        publication: metadata.pubdate,
        modified: metadata.modified_date,
    };
}

async function generateCache(book, id = '') {
    // Locations
    console.debug("Book", id, "Generating locations...");
    const locations = await book.locations.generate(1024);
    // Navigation
    console.debug("Book", id, "Generating navigation...");
    await loadAllSpines(book);
    const navigation = generateNavigation(book, book.navigation);
    return { locations, navigation };
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
