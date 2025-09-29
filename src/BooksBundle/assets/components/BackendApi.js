import { message } from "antd";
import axios from "axios";
import { Book } from "epubjs";

/**
 * @typedef {Object} LibrariesAPI
 */

/**
 * @typedef {Object} ShelvesAPI
 * @property {() => Promise<axiosInstance.AxiosResponse<any>>} get
 * @property {(id: string|number) => Promise<axiosInstance.AxiosResponse<any>>} getBooks
 * @property {(path:string, name: string) => Promise<axiosInstance.AxiosResponse<any>>} add
 * @property {(id: string|number, name: string) => Promise<axiosInstance.AxiosResponse<any>>} edit
 * @property {(id: string|number) => Promise<axiosInstance.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} BooksAPI
 * @property {(limit: number, offset: number) => Promise<axiosInstance.AxiosResponse<any>>} getAll
 * @property {(limit: number, offset: number) => Promise<axiosInstance.AxiosResponse<any>>} getNotInShelf
 * @property {() => Promise<axiosInstance.AxiosResponse<any>>} findNew
 * @property {(id: string|number) => string} epubUrl
 * @property {(id: string|number) => string} coverUrl
 * @property {(id: string|number) => Promise<axiosInstance.AxiosResponse<any>>} markUnread
 * @property {(id: string|number) => Promise<axiosInstance.AxiosResponse<any>>} markRead
 * @property {(id: string|number, position: string, page: number, update?: boolean) => Promise<axiosInstance.AxiosResponse<any>>} updatePosition
 * @property {(id: string|number) => Promise<axiosInstance.AxiosResponse<any>>} getMetadata
 * @property {(id: string|number) => Promise<axiosInstance.AxiosResponse<any>>} getId
 * @property {(id: string|number) => Promise<axiosInstance.AxiosResponse<any>>} delete
 * @property {(url: string) => Promise<axiosInstance.AxiosResponse<any>>} create
 * @property {(id: string|number) => Promise<axiosInstance.AxiosResponse<any>>} recreate
 */

/**
 * @typedef {Object} BooksBundleAPI
 * @property {(path: string) => string} [epubUrl]
 * @property {LibrariesAPI} [libraries]
 * @property {ShelvesAPI} [shelves]
 * @property {BooksAPI} [books]
 */

/** @type {BooksBundleAPI} */
export default function (apiUrl, libraryId) {

    const axiosInstance = axios.create({
        baseURL: apiUrl
    });

    // TODO da rimuovere quando è stato implementato ovunque, funzione per funzione
    axiosInstance.interceptors.response.use(
        res => {
            // Here you can handle successful responses
            return res; // Pass the response to the next then
        },
        err => {
            // Handle errors
            if (err.response) {
                // The request was made and the server responded with a status code
                // that falls out of the range of 2xx
                console.error('Error response:', err.response.data); // title, detail
                console.error('Error status:', err.response.status);
                message.error(err.response.data.detail, 5);
            } else if (err.request) {
                // The request was made but no response was received
                console.error('Error request:', err.request);
            } else {
                // Something happened in setting up the request that triggered an Error
                console.error('Error message:', err.message);
            }
            // Rethrow the error to allow callers to handle it
            return Promise.reject(err);
        }
    );

    const api = {};

    api.epubUrl = (url) => axiosInstance.getUri({ url: `/libraries/${libraryId}/epub${url}` });

    api.shelves = {};
    api.shelves.get = async () => axiosInstance.get(`/libraries/${libraryId}/shelves`);
    api.shelves.getBooks = async (id) => axiosInstance.get(`/libraries/${libraryId}/shelves/${id}/books`);
    api.shelves.add = async (path, name) => axiosInstance.post(`/libraries/${libraryId}/shelves`, { path, name });
    api.shelves.edit = async (id, name) => axiosInstance.put(`/libraries/${libraryId}/shelves/${id}`, { name });
    api.shelves.delete = async (id) => axiosInstance.delete(`/libraries/${libraryId}/shelves/${id}`);

    api.books = {};
    api.books.getAll = async (limit, offset) => axiosInstance.get(`/libraries/${libraryId}/books/all?limit=${limit}&offset=${offset}`);
    api.books.getNotInShelf = async (limit, offset) => axiosInstance.get(`/libraries/${libraryId}/books/not-in-shelves?limit=${limit}&offset=${offset}`);
    api.books.findNew = async () => axiosInstance.get(`/libraries/${libraryId}/books/find-new`)
    api.books.epubUrl = (id) => axiosInstance.getUri({ url: `/libraries/${libraryId}/books/${id}/epub` });
    api.books.coverUrl = (id) => axiosInstance.getUri({ url: `/libraries/${libraryId}/books/${id}/cover` });
    api.books.markUnread = async (id) => axiosInstance.put(`/libraries/${libraryId}/books/${id}/mark-unread`);
    api.books.markRead = async (id) => axiosInstance.put(`/libraries/${libraryId}/books/${id}/mark-read`);
    api.books.updatePosition = async (id, position, page, update = true) => axiosInstance.put(`/libraries/${libraryId}/books/${id}/position`, {position, page, update});
    api.books.getMetadata = async (id) => axiosInstance.get(`/libraries/${libraryId}/books/${id}/metadata`);
    api.books.getId = async (id) => axiosInstance.get(`/libraries/${libraryId}/books/${id}`);
    api.books.delete = async (id) => axiosInstance.delete(`/libraries/${libraryId}/books/${id}`);
    api.books.create = async (url) => {
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
    api.books.recreate = async (id) => {
        console.log("Book", id, "Recreating cache...");
        const epub = new Book(api.books.epubUrl(id), { openAs: 'epub' });
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

    return api;
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