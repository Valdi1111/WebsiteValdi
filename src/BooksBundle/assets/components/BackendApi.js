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
 * @property {(id: string|number, position: string, current: number, total: number, fraction: number, update?: boolean) => Promise<axios.AxiosResponse<any>>} updatePosition
 * @property {(id: string|number) => string} updatePositionUrl
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
    api._books.epubUrl = (id) => axiosInstance.getUri({ url: `/libraries/${libraryId}/books/${id}/book.epub` });
    api._books.coverUrl = (id) => axiosInstance.getUri({ url: `/libraries/${libraryId}/books/${id}/cover` });
    api._books.markUnread = async (id) => axiosInstance.put(`/libraries/${libraryId}/books/${id}/mark-unread`);
    api._books.markRead = async (id) => axiosInstance.put(`/libraries/${libraryId}/books/${id}/mark-read`);
    api._books.updatePosition = async (id, position, current, total, fraction, update = true) => axiosInstance.put(`/libraries/${libraryId}/books/${id}/position`, {position, current, total, fraction, update});
    api._books.updatePositionUrl = (id) => axiosInstance.getUri({ url: `/libraries/${libraryId}/books/${id}/position` });
    api._books.getMetadata = async (id) => axiosInstance.get(`/libraries/${libraryId}/books/${id}/metadata`);
    api._books.getId = async (id) => axiosInstance.get(`/libraries/${libraryId}/books/${id}`);
    api._books.delete = async (id) => axiosInstance.delete(`/libraries/${libraryId}/books/${id}`);
    api._books.create = async (url) => {
        console.debug("Creating book from link...");
        const epub = new Book(api.epubUrl(url));
        const book = await epub.opened;
        try {
            // Save
            const res = await axiosInstance.post(
                `/libraries/${libraryId}/books`,
                {
                    url,
                    book_metadata: generateMetadata(book.packaging.metadata)
                },
                {}
            );
            console.debug("Book", res.data.id, "Created successfully!");
            return Promise.resolve(res.data);
        } catch (e) {
            return Promise.reject(e);
        }
    }
    api._books.recreate = async (id) => {
        console.debug("Book", id, "Recreating cache...");
        const epub = new Book(api._books.epubUrl(id), { openAs: 'epub' });
        const book = await epub.opened;
        try {
            // Save
            const res = await axiosInstance.put(
                `/libraries/${libraryId}/books/${id}`,
                { book_metadata: generateMetadata(book.packaging.metadata) },
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
