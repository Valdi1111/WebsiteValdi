import { message } from "antd";
import axios from "axios";
import qs from "qs";

/**
 * @typedef {Object} VideosBundleAPI
 */

/** @type {VideosBundleAPI} */
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

    return api;
};