import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import axios from "axios";

/**
 * @typedef {Object} MessageData
 * @property {string} key - Unique identifier for the message (used by AntD `message`)
 * @property {string} loadingContent - Text to display while the operation is loading
 * @property {string} successContent - Text to display when the operation succeeds
 * @property {string} [errorContent] - Optional text to display if the operation fails
 * @property {number} [duration=2.5] - Duration (in seconds) for success/error messages. Defaults to 2.5
 */

/**
 * @typedef {Object} FileManagerAPI
 * @property {() => Promise<axios.AxiosResponse<any>>} fmInfo
 * @property {(id?: string|number, depth?: int, ignoreLastLevelLeaves?: boolean) => Promise<axios.AxiosResponse<any>>} fmFolders
 * @property {(id?: string|number) => Promise<axios.AxiosResponse<any>>} fmFiles
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} fmMeta
 * @property {(id: string|number, download?: boolean) => Promise<axios.AxiosResponse<any>>} fmDirect
 * @property {(id: string|number, download?: boolean) => Promise<axios.AxiosResponse<any>>} fmDirectHead
 * @property {(id: string|number, download?: boolean) => string} fmDirectUrl
 * @property {(id: string|number, name: string|number) => Promise<axios.AxiosResponse<any>>} fmMakeFile
 * @property {(id: string|number, name: string|number) => Promise<axios.AxiosResponse<any>>} fmMakeFolder
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} fmDeleteFile
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} fmDeleteFolder
 * @property {(id: string|number, name: string|number) => Promise<axios.AxiosResponse<any>>} fmRenameFile
 * @property {(id: string|number, name: string|number) => Promise<axios.AxiosResponse<any>>} fmRenameFolder
 * @property {(id: string|number, to: string|number) => Promise<axios.AxiosResponse<any>>} fmCopy
 * @property {(id: string|number, to: string|number) => Promise<axios.AxiosResponse<any>>} fmMove
 * @property {(id: string|number, download?: boolean) => string} fmUploadUrl
 * @property {(size?: string, type?: string, name?: string) => string} fmIconUrl
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} fmDownload
 *
 * @property {() => FileManagerAPI} [withErrorHandling]
 * @property {(messageData: MessageData) => FileManagerAPI} [withLoadingMessage]
 */

/** @type {FileManagerAPI} */
export default function (apiUrl, { message }) {

    const axiosInstance = axios.create({
        baseURL: apiUrl
    });

    const api = {};

    api.fmInfo = async () => axiosInstance.get(`/info`);
    api.fmFolders = async (id = '/', depth = -1, ignoreLastLevelLeaves = false) => axiosInstance.get(`/folders`, { params: { id, depth, ignoreLastLevelLeaves } });
    api.fmFiles = async (id = '/') => axiosInstance.get(`/files`, { params: { id } });
    api.fmMeta = async (id) => axiosInstance.get(`/meta`, { params: { id } });
    api.fmDirect = async (id, download = false) => axiosInstance.get(`/direct`, { params: { id, download } });
    api.fmDirectHead = async (id, download = false) => axiosInstance.head(`/direct`, { params: { id, download } });
    api.fmDirectUrl = (id, download = false) => axiosInstance.getUri({ url: `/direct`, params: { id, download } });
    api.fmMakeFile = async (id, name) => axiosInstance.post(`/make-file`, { id, name });
    api.fmMakeFolder = async (id, name) => axiosInstance.post(`/make-folder`, { id, name });
    api.fmDeleteFile = async (id) => axiosInstance.post(`/delete-file`, { id });
    api.fmDeleteFolder = async (id) => axiosInstance.post(`/delete-folder`, { id });
    api.fmRenameFile = async (id, name) => axiosInstance.post(`/rename-file`, { id, name });
    api.fmRenameFolder = async (id, name) => axiosInstance.post(`/rename-folder`, { id, name });
    api.fmCopy = async (id, to) => axiosInstance.post(`/copy`, { id, to });
    api.fmMove = async (id, to) => axiosInstance.post(`/move`, { id, to });
    api.fmUploadUrl = (id) => axiosInstance.getUri({ url: `/upload`, params: { id } });
    api.fmIconUrl = (size = 'big', type = 'none', name = undefined) => axiosInstance.getUri({ url: `/icons/${size}/${type}/${name}.svg` });
    api.fmDownload = (id) => api.fmDirectHead(id, true).then(
        res => {
            const disposition = res.headers.get("Content-Disposition");
            let filename = "file";
            if (disposition && disposition.includes("filename=")) {
                filename = disposition.match(/filename="?([^"]+)"?/)[1];
            }
            const link = document.createElement('a');
            link.href = api.fmDirectUrl(id, true);
            link.download = filename;
            link.click();
            link.remove();
        },
    );

    return {
        ...api,
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