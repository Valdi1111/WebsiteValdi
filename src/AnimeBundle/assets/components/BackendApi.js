import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import axios from "axios";

/**
 * @typedef {Object} TrackerMediaAPI
 * @property {(tracker: string, id: string|number) => Promise<axios.AxiosResponse<any>>} animeTitle
 * @property {(tracker: string, id: string|number) => Promise<axios.AxiosResponse<any>>} mangaTitle
 */

/**
 * @typedef {Object} DownloadsAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} table
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(data: Object) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} retry
 */

/**
 * @typedef {Object} ListAnimeAPI
 * @property {(tracker: string, params: Object) => Promise<axios.AxiosResponse<any>>} table
 * @property {(tracker: string, id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(tracker: string) => Promise<axios.AxiosResponse<any>>} refresh
 */

/**
 * @typedef {Object} ListMangaAPI
 * @property {(tracker: string, params: Object) => Promise<axios.AxiosResponse<any>>} table
 * @property {(tracker: string, id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(tracker: string) => Promise<axios.AxiosResponse<any>>} refresh
 */

/**
 * @typedef {Object} SeasonFoldersAPI
 * @property {(tracker: string, params: Object) => Promise<axios.AxiosResponse<any>>} table
 * @property {(tracker: string, id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(tracker: string, id: string|number) => Promise<axios.AxiosResponse<any>>} getDownloads
 * @property {(tracker: string, data: Object) => Promise<axios.AxiosResponse<any>>} add
 * @property {(tracker: string, id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} AnimeBundleAPI
 * @property {() => string} [fmUrl]
 * @property {(id: string) => string} [fmDirectUrl]
 * @property {() => TrackerMediaAPI} [tracker]
 * @property {() => DownloadsAPI} [downloads]
 * @property {() => ListAnimeAPI} [listAnime]
 * @property {() => ListMangaAPI} [listManga]
 * @property {() => SeasonFoldersAPI} [seasonFolders]
 *
 * @property {() => AnimeBundleAPI} [withErrorHandling]
 * @property {(messageData: MessageData) => AnimeBundleAPI} [withLoadingMessage]
 */

/** @type {AnimeBundleAPI} */
export default function (apiUrl, { message }) {

    const axiosInstance = axios.create({
        baseURL: apiUrl
    });

    const api = {};

    api.fmUrl = () => axiosInstance.getUri({ url: `/files` });
    api.fmDirectUrl = (id) => axiosInstance.getUri({ url: `/files/direct`, params: { id } });

    api._tracker = {};
    api._tracker.animeTitle = async (tracker, id) => axiosInstance.get(`/${tracker}/anime-title/${id}`);
    api._tracker.mangaTitle = async (tracker, id) => axiosInstance.get(`/${tracker}/manga-title/${id}`);

    api._downloads = {};
    api._downloads.table = async (params) => axiosInstance.get(`/downloads/table`, { params });
    api._downloads.getId = async (id) => axiosInstance.get(`/downloads/${id}`);
    api._downloads.add = async (data) => axiosInstance.post(`/downloads`, data);
    api._downloads.retry = async (id) => axiosInstance.post(`/downloads/${id}/retry`);

    api._listAnime = {};
    api._listAnime.table = async (tracker, params) => axiosInstance.get(`/${tracker}/list-anime/table`, { params });
    api._listAnime.getId = async (tracker, id) => axiosInstance.get(`/${tracker}/list-anime/${id}`);
    api._listAnime.refresh = async (tracker) => axiosInstance.post(`/${tracker}/list-anime/refresh`);

    api._listManga = {};
    api._listManga.table = async (tracker, params) => axiosInstance.get(`/${tracker}/list-manga/table`, { params });
    api._listManga.getId = async (tracker, id) => axiosInstance.get(`/${tracker}/list-manga/${id}`);
    api._listManga.refresh = async (tracker) => axiosInstance.post(`/${tracker}/list-manga/refresh`);

    api._seasonFolders = {};
    api._seasonFolders.table = async (tracker, params) => axiosInstance.get(`/${tracker}/season-folders/table`, { params });
    api._seasonFolders.getId = async (tracker, id) => axiosInstance.get(`/${tracker}/season-folders/${id}`);
    api._seasonFolders.getDownloads = async (tracker, id) => axiosInstance.get(`/${tracker}/season-folders/${id}/downloads`);
    api._seasonFolders.add = async (tracker, data) => axiosInstance.post(`/${tracker}/season-folders`, data);
    api._seasonFolders.delete = async (tracker, id) => axiosInstance.delete(`/${tracker}/season-folders/${id}`);

    return {
        ...api,
        tracker: () => api._tracker,
        downloads: () => api._downloads,
        listAnime: () => api._listAnime,
        listManga: () => api._listManga,
        seasonFolders: () => api._seasonFolders,
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
