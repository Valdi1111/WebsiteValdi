import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import { CURRENT_USER_URL } from "@CoreBundle/components/user/useCurrentUserLoader";
import axios from "axios";

/**
 * A role of the logged-in user (GET /api/me/roles).
 *
 * @typedef {Object} ProfileRole
 * @property {string} name role code, e.g. ROLE_USER_BOOKS
 * @property {string} label display name
 * @property {string|null} bundle e.g. BooksBundle, null for the site-wide roles
 * @property {boolean} assigned false when inherited through the role hierarchy
 */

/**
 * @typedef {Object} ProfileAPI
 * @property {() => Promise<axios.AxiosResponse<ProfileRole[]>>} getRoles
 * @property {() => ProfileAPI} withErrorHandling
 * @property {(messageData: any) => ProfileAPI} withLoadingMessage
 */

/**
 * Backend API of the profile page, under the current user endpoint.
 *
 * @returns {ProfileAPI}
 */
export default function createProfileApi({ message }) {
    const axiosInstance = axios.create({
        baseURL: CURRENT_USER_URL,
    });

    const api = {};

    api.getRoles = async () => axiosInstance.get('/roles');

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
}
