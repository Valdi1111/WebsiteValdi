import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import axios from "axios";

/**
 * @typedef {Object} HoyoverseAccountsAPI
 * @property {() => Promise<axios.AxiosResponse<any>>} get
 * @property {(data: { cookie: string }) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} sync
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} HoyoverseGameProfilesAPI
 * @property {(accountId: string|number) => Promise<axios.AxiosResponse<any>>} get
 * @property {(accountId: string|number, gameProfileId: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(accountId: string|number, gameProfileId: string|number, data: Object) => Promise<axios.AxiosResponse<any>>} updateSettings
 * @property {(accountId: string|number, gameProfileId: string|number) => Promise<axios.AxiosResponse<any>>} getDiaryMeta
 * @property {(accountId: string|number, gameProfileId: string|number, params: { period: string, currency: string }) => Promise<axios.AxiosResponse<any>>} getDiarySummary
 * @property {(accountId: string|number, gameProfileId: string|number, params: { period: string, currency: string, page?: number, limit?: number, filter?: string, date?: string }) => Promise<axios.AxiosResponse<any>>} getDiaryEntries
 */

/**
 * @typedef {Object} HoyoverseBundleAPI
 * @property {() => HoyoverseAccountsAPI} accounts
 * @property {() => HoyoverseGameProfilesAPI} gameProfiles
 * @property {() => HoyoverseBundleAPI} withErrorHandling
 * @property {(messageData: any) => HoyoverseBundleAPI} withLoadingMessage
 */

/** @type {HoyoverseBundleAPI} */
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
    api._accounts.loginStart = async (data) => axiosInstance.post(`/accounts/login/start`, data);
    api._accounts.reloginStart = async (accountId, data) => axiosInstance.post(`/accounts/${accountId}/relogin/start`, data);
    api._accounts.loginCaptcha = async (data) => axiosInstance.post(`/accounts/login/captcha`, data);
    api._accounts.loginVerifyEmail = async (data) => axiosInstance.post(`/accounts/login/verify-email`, data);
    api._accounts.loginResendEmail = async (data) => axiosInstance.post(`/accounts/login/resend-email`, data);
    api._accounts.getGeetestFrameUrl = (loginSessionId) => `${apiUrl}/accounts/login/geetest-frame?login_session_id=${encodeURIComponent(loginSessionId)}`;

    api._gameProfiles = {};
    api._gameProfiles.get = async (id) => axiosInstance.get(`/accounts/${id}/gameProfiles`);
    api._gameProfiles.getId = async (accountId, gameProfileId) => axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}`);
    api._gameProfiles.updateSettings = async (accountId, gameProfileId, data) => axiosInstance.patch(`/accounts/${accountId}/gameProfiles/${gameProfileId}/settings`, data);

    // Diary routes
    api._gameProfiles.getDiaryMeta = async (accountId, gameProfileId) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/diary/meta`);
    api._gameProfiles.getDiarySummary = async (accountId, gameProfileId, params) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/diary/summary`, { params });
    api._gameProfiles.getDiaryEntries = async (accountId, gameProfileId, params) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/diary/entries`, { params });

    // Pulls routes
    api._gameProfiles.syncPulls = async (accountId, gameProfileId) =>
        axiosInstance.post(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/sync`);
    api._gameProfiles.importPowershellPulls = async (accountId, gameProfileId, data) =>
        axiosInstance.post(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/import-powershell`, data);
    api._gameProfiles.importBackupPulls = async (accountId, gameProfileId, importerKey, file) => {
        const formData = new FormData();
        formData.append("importer", importerKey);
        formData.append("file", file);
        return axiosInstance.post(
            `/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/import-backup`,
            formData,
            { headers: { "Content-Type": "multipart/form-data" } }
        );
    };
    api._gameProfiles.getPullsOperationStatus = async (accountId, gameProfileId, operationId) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/operations/${encodeURIComponent(operationId)}`);
    api._gameProfiles.getPullsOverview = async (accountId, gameProfileId) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/overview`);
    api._gameProfiles.getPulls = async (accountId, gameProfileId, params) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls`, { params });

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