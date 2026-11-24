import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import axios from "axios";

// Core routes without host: the same URL on every subdomain
export const ADMIN_API_URL = '/api/admin';

/**
 * A role of the site (GET /api/admin/roles).
 *
 * @typedef {Object} AdminRole
 * @property {string} name role code, e.g. ROLE_USER_BOOKS
 * @property {string} label display name
 * @property {string|null} bundle e.g. BooksBundle, null for the site-wide roles
 */

/**
 * A user of the site, as the admins see it (GET /api/admin/users).
 *
 * @typedef {Object} AdminUser
 * @property {number} id
 * @property {string} email
 * @property {string[]} roles assigned role codes, sorted; the inherited ones are not included
 * @property {boolean} discord_linked
 * @property {string|null} discord_username null when not linked, or linked by hand before the profile page could do it
 * @property {boolean} telegram_linked
 * @property {string|null} telegram_username without the @; null when not linked, linked by hand, or without a username
 * @property {boolean} enabled false when disabled: the user cannot log in, not even with an API token
 * @property {string|null} disabled_at ISO 8601, null when enabled
 */

/**
 * @typedef {Object} AdminAPI
 * @property {() => Promise<axios.AxiosResponse<AdminRole[]>>} getRoles
 * @property {() => Promise<axios.AxiosResponse<AdminUser[]>>} getUsers
 * @property {(email: string, password: string|null, roles: string[]) => Promise<axios.AxiosResponse<AdminUser>>} createUser
 * @property {(id: number, changes: { enabled?: boolean }) => Promise<axios.AxiosResponse<AdminUser>>} updateUser
 * @property {() => AdminAPI} withErrorHandling
 * @property {(messageData: any) => AdminAPI} withLoadingMessage
 */

/**
 * Backend API of the admin pages: every route requires ROLE_ADMIN.
 *
 * @returns {AdminAPI}
 */
export default function createAdminApi({ message }) {
    const axiosInstance = axios.create({
        baseURL: ADMIN_API_URL,
    });

    const api = {};

    // Every role of the site, site-wide ones first, then by bundle
    api.getRoles = async () => axiosInstance.get('/roles');
    // Sorted by email
    api.getUsers = async () => axiosInstance.get('/users');
    // 201 with the user; the backend emails them a link to choose the password, never the password set here.
    // 422 with the violations on email, password and roles
    api.createUser = async (email, password, roles) => axiosInstance.post('/users', { email, password, roles });
    // 422 when an admin disables their own account
    api.updateUser = async (id, changes) => axiosInstance.patch(`/users/${id}`, changes);

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
