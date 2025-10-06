import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import axios from "axios";

/**
 * @typedef {Object} DownloadsAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} table
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(data: Object) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} retry
 */

/**
 * @typedef {Object} ListAnimeAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} table
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {() => Promise<axios.AxiosResponse<any>>} refresh
 */

/**
 * @typedef {Object} ListMangaAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} table
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {() => Promise<axios.AxiosResponse<any>>} refresh
 */

/**
 * @typedef {Object} SeasonsFolderAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} table
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getDownloads
 * @property {(data: Object) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} AnimeBundleAPI
 * @property {() => string} [fmUrl]
 * @property {(id: string) => string} [fmDirectUrl]
 * @property {() => DownloadsAPI} [downloads]
 * @property {() => ListAnimeAPI} [listAnime]
 * @property {() => ListMangaAPI} [listManga]
 * @property {() => SeasonsFolderAPI} [seasonsFolder]
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

    api._downloads = {};
    api._downloads.table = async (params) => axiosInstance.get(`/downloads/table`, { params });
    api._downloads.getId = async (id) => axiosInstance.get(`/downloads/${id}`);
    api._downloads.add = async (data) => axiosInstance.post(`/downloads`, data);
    api._downloads.retry = async (id) => axiosInstance.post(`/downloads/${id}/retry`);

    api._listAnime = {};
    api._listAnime.table = async (params) => axiosInstance.get(`/list-anime/table`, { params });
    api._listAnime.getId = async (id) => axiosInstance.get(`/list-anime/${id}`);
    api._listAnime.refresh = async () => axiosInstance.post(`/list-anime/refresh`);

    api._listManga = {};
    api._listManga.table = async (params) => axiosInstance.get(`/list-manga/table`, { params });
    api._listManga.getId = async (id) => axiosInstance.get(`/list-manga/${id}`);
    api._listManga.refresh = async () => axiosInstance.post(`/list-manga/refresh`);

    api._seasonsFolder = {};
    api._seasonsFolder.table = async (params) => axiosInstance.get(`/season-folders/table`, { params });
    api._seasonsFolder.getId = async (id) => axiosInstance.get(`/season-folders/${id}`);
    api._seasonsFolder.getDownloads = async (id) => axiosInstance.get(`/season-folders/${id}/downloads`);
    api._seasonsFolder.add = async (data) => axiosInstance.post(`/season-folders`, data);
    api._seasonsFolder.delete = async (id) => axiosInstance.delete(`/season-folders/${id}`);

    return {
        ...api,
        downloads: () => api._downloads,
        listAnime: () => api._listAnime,
        listManga: () => api._listManga,
        seasonsFolder: () => api._seasonsFolder,
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