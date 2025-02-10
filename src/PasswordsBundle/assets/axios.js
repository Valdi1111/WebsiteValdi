import { API_URL } from "@PasswordsBundle/constants";
import { message } from "antd";
import axios from "axios";

const axiosInstance = axios.create({
    baseURL: API_URL
});

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

export default axiosInstance;