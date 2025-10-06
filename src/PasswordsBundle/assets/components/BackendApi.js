import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import axios from "axios";

/**
 * @typedef {Object} CredentialsAPI
 * @property {(params: Object) => Promise<axios.AxiosResponse<any>>} table
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(data: Object) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number, data: Object) => Promise<axios.AxiosResponse<any>>} edit
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} PasswordsBundleAPI
 * @property {() => CredentialsAPI} [credentials]
 *
 * @property {() => PasswordsBundleAPI} [withErrorHandling]
 * @property {(messageData: MessageData) => PasswordsBundleAPI} [withLoadingMessage]
 */

/** @type {PasswordsBundleAPI} */
export default function (apiUrl, { message }) {

    const axiosInstance = axios.create({
        baseURL: apiUrl
    });

    const api = {};

    api._credentials = {};
    api._credentials.table = async (params) => axiosInstance.get(`/credentials/table`, { params });
    api._credentials.getId = async (id) => axiosInstance.get(`/credentials/${id}`);
    api._credentials.add = async (data) => axiosInstance.post(`/credentials`, data);
    api._credentials.edit = async (id, data) => axiosInstance.put(`/credentials/${id}`, data);
    api._credentials.delete = async (id, data) => axiosInstance.delete(`/credentials/${id}`, data);

    return {
        ...api,
        credentials: () => api._credentials,
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