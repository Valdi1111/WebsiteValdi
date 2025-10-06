import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import { Book } from "epubjs";
import axios from "axios";

/**
 * @typedef {Object} LibrariesAPI
 */

/**
 * @typedef {Object} ShelvesAPI
 * @property {() => Promise<axios.AxiosResponse<any>>} get
 * @property {(id: string|number, withSubShelves?: boolean) => Promise<axios.AxiosResponse<any>>} getBooks
 * @property {(path:string, name: string) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number, name: string) => Promise<axios.AxiosResponse<any>>} edit
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} BooksAPI
 * @property {(limit: number, offset: number) => Promise<axios.AxiosResponse<any>>} getAll
 * @property {(limit: number, offset: number) => Promise<axios.AxiosResponse<any>>} getNotInShelf
 * @property {() => Promise<axios.AxiosResponse<any>>} findNew
 * @property {(id: string|number) => string} epubUrl
 * @property {(id: string|number) => string} coverUrl
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} markUnread
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} markRead
 * @property {(id: string|number, position: string, page: number, update?: boolean) => Promise<axios.AxiosResponse<any>>} updatePosition
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getMetadata
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 * @property {(url: string) => Promise<axios.AxiosResponse<any>>} create
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} recreate
 */

/**
 * @typedef {Object} BooksBundleAPI
 * @property {() => string} [fmUrl]
 * @property {(path: string) => string} [epubUrl]
 * @property {() => LibrariesAPI} [libraries]
 * @property {() => ShelvesAPI} [shelves]
 * @property {() => BooksAPI} [books]
 *
 * @property {() => BooksBundleAPI} [withErrorHandling]
 * @property {(messageData: MessageData) => BooksBundleAPI} [withLoadingMessage]
 */

/** @type {BooksBundleAPI} */
export default function (apiUrl, libraryId, { message }) {

    const axiosInstance = axios.create({
        baseURL: apiUrl
    });

    const api = {};

    api.fmUrl = () => axiosInstance.getUri({ url: `/libraries/${libraryId}/files` });
    api.epubUrl = (url) => axiosInstance.getUri({ url: `/libraries/${libraryId}/epub${url}` });

    api._shelves = {};
    api._shelves.get = async () => axiosInstance.get(`/libraries/${libraryId}/shelves`);
    api._shelves.getBooks = async (id, withSubShelves = true) => axiosInstance.get(`/libraries/${libraryId}/shelves/${id}/books`, { params: { withSubShelves } });
    api._shelves.add = async (path, name) => axiosInstance.post(`/libraries/${libraryId}/shelves`, { path, name });
    api._shelves.edit = async (id, name) => axiosInstance.put(`/libraries/${libraryId}/shelves/${id}`, { name });
    api._shelves.delete = async (id) => axiosInstance.delete(`/libraries/${libraryId}/shelves/${id}`);

    api._books = {};
    api._books.getAll = async (limit, offset) => axiosInstance.get(`/libraries/${libraryId}/books/all`, { params: { limit, offset } });
    api._books.getNotInShelf = async (limit, offset) => axiosInstance.get(`/libraries/${libraryId}/books/not-in-shelves`, { params: { limit, offset } });
    api._books.findNew = async () => axiosInstance.get(`/libraries/${libraryId}/books/find-new`)
    api._books.epubUrl = (id) => axiosInstance.getUri({ url: `/libraries/${libraryId}/books/${id}/epub` });
    api._books.coverUrl = (id) => axiosInstance.getUri({ url: `/libraries/${libraryId}/books/${id}/cover` });
    api._books.markUnread = async (id) => axiosInstance.put(`/libraries/${libraryId}/books/${id}/mark-unread`);
    api._books.markRead = async (id) => axiosInstance.put(`/libraries/${libraryId}/books/${id}/mark-read`);
    api._books.updatePosition = async (id, position, page, update = true) => axiosInstance.put(`/libraries/${libraryId}/books/${id}/position`, {position, page, update});
    api._books.getMetadata = async (id) => axiosInstance.get(`/libraries/${libraryId}/books/${id}/metadata`);
    api._books.getId = async (id) => axiosInstance.get(`/libraries/${libraryId}/books/${id}`);
    api._books.delete = async (id) => axiosInstance.delete(`/libraries/${libraryId}/books/${id}`);
    api._books.create = async (url) => {
        console.log("Creating book from link...");
        const epub = new Book(api.epubUrl(url));
        const book = await epub.opened;
        try {
            // Generate Cache
            const { locations, navigation } = await generateCache(book);
            // Save
            const res = await axiosInstance.post(
                `/libraries/${libraryId}/books`,
                {
                    url,
                    book_cache: { locations, navigation },
                    book_metadata: generateMetadata(book.packaging.metadata)
                },
                {}
            );
            console.log("Book", res.data.id, "Created successfully!");
            return Promise.resolve(res.data);
        } catch (e) {
            return Promise.reject(e);
        }
    }
    api._books.recreate = async (id) => {
        console.log("Book", id, "Recreating cache...");
        const epub = new Book(api.books().epubUrl(id), { openAs: 'epub' });
        const book = await epub.opened;
        try {
            // Generate Cache
            const { locations, navigation } = await generateCache(book, id);
            // Save
            const res = await axiosInstance.put(
                `/libraries/${libraryId}/books/${id}`,
                { book_cache: { locations, navigation }, book_metadata: generateMetadata(book.packaging.metadata) },
                {}
            );
            return Promise.resolve(res.data);
        } catch (e) {
            return Promise.reject(e);
        }
    }

    return {
        ...api,
        shelves: () => api._shelves,
        books: () => api._books,
        withErrorHandling: () => createProxy(
            api,
            (promiseFn) => withErrorHandling(promiseFn, message)
        ),
        withLoadingMessage: (messageData) => createProxy(
            api,
            (promiseFn) => withLoadingMessage(promiseFn, message, messageData)
        ),
    };
};

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
        else if (book.spine.get("Text/" + item.href.split('/').pop()) !== null) {
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