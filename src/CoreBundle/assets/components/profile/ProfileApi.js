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
 * An API token of the logged-in user (GET /api/me/tokens).
 *
 * @typedef {Object} ApiToken
 * @property {number} id
 * @property {string|null} description null for the tokens made before descriptions existed
 * @property {boolean} valid false when disabled
 * @property {string|null} token the value: only from getToken() and createToken()
 */

/**
 * The Discord account linked to the logged-in user (GET /api/me/discord): everything but `linked` is null when
 * there is none.
 *
 * @typedef {Object} DiscordAccount
 * @property {boolean} linked
 * @property {string|null} username unique Discord username, without the @; null also when linked, for the ids set
 *   by hand before the OAuth flow existed (no names stored until linked again)
 * @property {string|null} global_name display name, null when the user has not set one
 * @property {string|null} avatar_url Discord's default avatar when the user has none; can still fail to load (stale hash)
 */

/**
 * The Telegram account linked to the logged-in user (GET /api/me/telegram): everything but `linked` is null when
 * there is none.
 *
 * @typedef {Object} TelegramAccount
 * @property {boolean} linked
 * @property {string|null} username public username, without the @; null when the account has none
 * @property {string|null} name display name; null also when linked, with the username, for the ids set by hand
 *   before the OpenID Connect flow existed (no names stored until linked again)
 */

/**
 * @typedef {Object} ProfileAPI
 * @property {() => Promise<axios.AxiosResponse<ProfileRole[]>>} getRoles
 * @property {(file: File) => Promise<axios.AxiosResponse<import("../user/CurrentUserContext").CurrentUser>>} uploadAvatar
 * @property {() => Promise<axios.AxiosResponse<import("../user/CurrentUserContext").CurrentUser>>} removeAvatar
 * @property {(currentPassword: string, newPassword: string) => Promise<axios.AxiosResponse<void>>} changePassword
 * @property {() => Promise<axios.AxiosResponse<ApiToken[]>>} getTokens
 * @property {(id: number) => Promise<axios.AxiosResponse<ApiToken>>} getToken
 * @property {(description: string) => Promise<axios.AxiosResponse<ApiToken>>} createToken
 * @property {(id: number, changes: { description?: string, valid?: boolean }) => Promise<axios.AxiosResponse<ApiToken>>} updateToken
 * @property {(id: number) => Promise<axios.AxiosResponse<void>>} deleteToken
 * @property {() => Promise<axios.AxiosResponse<DiscordAccount>>} getDiscord
 * @property {() => Promise<axios.AxiosResponse<void>>} unlinkDiscord
 * @property {() => Promise<axios.AxiosResponse<TelegramAccount>>} getTelegram
 * @property {() => Promise<axios.AxiosResponse<void>>} unlinkTelegram
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
    // Both answer with the updated current user
    api.uploadAvatar = async (file) => {
        const formData = new FormData();
        formData.append('avatar', file);
        return axiosInstance.post('/avatar', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
    };
    api.removeAvatar = async () => axiosInstance.delete('/avatar');
    // 204; 422 with the violations, 429 after too many wrong current passwords
    api.changePassword = async (currentPassword, newPassword) => axiosInstance.post('/password', {
        current_password: currentPassword,
        new_password: newPassword,
    });
    // API tokens: the list has no values, getToken() gives one
    api.getTokens = async () => axiosInstance.get('/tokens');
    api.getToken = async (id) => axiosInstance.get(`/tokens/${id}`);
    api.createToken = async (description) => axiosInstance.post('/tokens', { description });
    api.updateToken = async (id, changes) => axiosInstance.patch(`/tokens/${id}`, changes);
    api.deleteToken = async (id) => axiosInstance.delete(`/tokens/${id}`);
    // Discord: linking is not a request from here but a full-page navigation to the OAuth flow
    // (/profile/discord/connect); unlinking answers 204 also when nothing is linked
    api.getDiscord = async () => axiosInstance.get('/discord');
    api.unlinkDiscord = async () => axiosInstance.delete('/discord');
    // Telegram: the same, with the OpenID Connect flow of /profile/telegram/connect
    api.getTelegram = async () => axiosInstance.get('/telegram');
    api.unlinkTelegram = async () => axiosInstance.delete('/telegram');

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
