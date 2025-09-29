import { message } from "antd";
import axios from "axios";
import qs from "qs";


/**
 * @typedef {Object} CredentialsAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} get
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(data: Object) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number, data: Object) => Promise<axios.AxiosResponse<any>>} edit
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} PasswordsBundleAPI
 * @property {CredentialsAPI} [credentials]
 */

/** @type {PasswordsBundleAPI} */
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

    api.credentials = {};
    api.credentials.get = async (params) => axiosInstance.get(`/credentials?${qs.stringify(params)}`);
    api.credentials.getId = async (id) => axiosInstance.get(`/credentials/${id}`);
    api.credentials.add = async (data) => axiosInstance.post(`/credentials`, data);
    api.credentials.edit = async (id, data) => axiosInstance.put(`/credentials/${id}`, data);
    api.credentials.delete = async (id, data) => axiosInstance.delete(`/credentials/${id}`, data);

    return api;
};