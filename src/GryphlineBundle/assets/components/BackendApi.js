import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import axios from "axios";

/**
 * @typedef {Object} GryphlineAccountsAPI
 * @property {() => Promise<axios.AxiosResponse<any>>} get
 * @property {(data: { account_token: string }) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} sync
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} GryphlineGameProfilesAPI
 * @property {(accountId: string|number) => Promise<axios.AxiosResponse<any>>} get
 * @property {(accountId: string|number, gameProfileId: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(accountId: string|number, gameProfileId: string|number, data: Object) => Promise<axios.AxiosResponse<any>>} updateSettings
 */

/**
 * @typedef {Object} GryphlineBundleAPI
 * @property {() => GryphlineAccountsAPI} accounts
 * @property {() => GryphlineGameProfilesAPI} gameProfiles
 * @property {() => GryphlineBundleAPI} withErrorHandling
 * @property {(messageData: any) => GryphlineBundleAPI} withLoadingMessage
 */

/** @type {GryphlineBundleAPI} */
export default function (apiUrl, { message }) {
    const axiosInstance = axios.create({
        baseURL: apiUrl
    });

    const api = {};

    api._accounts = {};
    api._accounts.get = async () => axiosInstance.get(`/accounts`);
    api._accounts.add = async (data) => axiosInstance.post(`/accounts`, data);
    api._accounts.sync = async (id) => axiosInstance.post(`/accounts/${id}/sync`);
    api._accounts.delete = async (id) => axiosInstance.delete(`/accounts/${id}`);

    api._gameProfiles = {};
    api._gameProfiles.get = async (id) => axiosInstance.get(`/accounts/${id}/gameProfiles`);
    api._gameProfiles.getId = async (accountId, gameProfileId) => axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}`);
    api._gameProfiles.updateSettings = async (accountId, gameProfileId, data) => axiosInstance.patch(`/accounts/${accountId}/gameProfiles/${gameProfileId}/settings`, data);

    return {
        ...api,
        accounts: () => api._accounts,
        gameProfiles: () => api._gameProfiles,
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
