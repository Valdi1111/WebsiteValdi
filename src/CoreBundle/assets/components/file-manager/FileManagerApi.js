import axios from "axios";
import { message } from "antd";

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

    api.fmInfo = async () => axiosInstance.get(`/info`);
    api.fmFolders = async (id = '/') => axiosInstance.get(`/folders`, { params: { id } });
    api.fmFiles = async (id = '/') => axiosInstance.get(`/files`, { params: { id } });
    api.fmDirect = async (id, download = false) => axiosInstance.get(`/direct`, { params: { id, download } });
    api.fmDirectHead = async (id, download = false) => axiosInstance.head(`/direct`, { params: { id, download } });
    api.fmDirectUrl = (id, download = false) => axiosInstance.getUri({ url: `/direct`, params: { id, download } });
    api.fmMakeFile = async (id, name) => axiosInstance.post(`/make-file`, { id, name });
    api.fmMakeDir = async (id, name) => axiosInstance.post(`/make-dir`, { id, name });
    api.fmDeleteFile = async (id) => axiosInstance.post(`/delete-file`, { id });
    api.fmDeleteDir = async (id) => axiosInstance.post(`/delete-dir`, { id });
    api.fmRename = async (id, name) => axiosInstance.post(`/rename`, { id, name });
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

    return api;
};