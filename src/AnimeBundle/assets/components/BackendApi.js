import { message } from "antd";
import axios from "axios";
import qs from "qs";

/**
 * @typedef {Object} DownloadsAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} get
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(data: Object) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} retry
 */

/**
 * @typedef {Object} ListAnimeAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} get
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 */

/**
 * @typedef {Object} ListMangaAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} get
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 */

/**
 * @typedef {Object} SeasonsFolderAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} get
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} available
 * @property {(data: Object) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} AnimeBundleAPI
 * @property {DownloadsAPI} [downloads]
 * @property {ListAnimeAPI} [listAnime]
 * @property {ListMangaAPI} [listManga]
 * @property {SeasonsFolderAPI} [seasonsFolder]
 */

/** @type {AnimeBundleAPI} */
export default function (apiUrl) {

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

    api.downloads = {};
    api.downloads.get = async (params) => axiosInstance.get(`/downloads?${qs.stringify(params)}`);
    api.downloads.getId = async (id) => axiosInstance.get(`/downloads/${id}`);
    api.downloads.add = async (data) => axiosInstance.post(`/downloads`, data);
    api.downloads.retry = async (id) => axiosInstance.post(`/downloads/${id}/retry`);

    api.listAnime = {};
    api.listAnime.get = async (params) => axiosInstance.get(`/list-anime?${qs.stringify(params)}`);
    api.listAnime.getId = async (id) => axiosInstance.get(`/list-anime/${id}`);

    api.listManga = {};
    api.listManga.get = async (params) => axiosInstance.get(`/list-manga?${qs.stringify(params)}`);
    api.listManga.getId = async (id) => axiosInstance.get(`/list-manga/${id}`);

    api.seasonsFolder = {};
    api.seasonsFolder.get = async (params) => axiosInstance.get(`/season-folders?${qs.stringify(params)}`);
    api.seasonsFolder.getId = async (id) => axiosInstance.get(`/season-folders/${id}`);
    api.seasonsFolder.available = async (params) => axiosInstance.get(`/season-folders/available?${qs.stringify(params)}`);
    api.seasonsFolder.add = async (data) => axiosInstance.post(`/season-folders`, data);
    api.seasonsFolder.delete = async (id) => axiosInstance.delete(`/season-folders/${id}`);

    return api;
};