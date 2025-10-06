import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import axios from "axios";

/**
 * @typedef {Object} VideosBundleAPI
 * @property {() => string} [fmUrl]
 * @property {(id: string) => string} [fmDirectUrl]
 */

/** @type {VideosBundleAPI} */
export default function (apiUrl, { message }) {

    const axiosInstance = axios.create({
        baseURL: apiUrl
    });

    const api = {};

    api.fmUrl = () => axiosInstance.getUri({ url: `/files` });
    api.fmDirectUrl = (id) => axiosInstance.getUri({ url: `/files/direct`, params: { id } });

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