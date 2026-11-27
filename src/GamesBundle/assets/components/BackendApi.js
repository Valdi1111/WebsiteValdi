import { createProxy, withErrorHandling, withLoadingMessage } from "@CoreBundle/api-utils";
import axios from "axios";

/**
 * Backend API client shared by every game provider. One instance is created per provider
 * (see ProviderScope) with that provider's base URL, e.g. "/api/hoyoverse".
 * Endpoints a provider does not implement are simply never called by its UI.
 *
 * @typedef {Object} GamesAccountsAPI
 * @property {() => Promise<axios.AxiosResponse<any>>} get
 * @property {(data: Object) => Promise<axios.AxiosResponse<any>>} add
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} sync
 * @property {(id: string|number) => Promise<axios.AxiosResponse<any>>} delete
 */

/**
 * @typedef {Object} GamesGameProfilesAPI
 * @property {(accountId: string|number) => Promise<axios.AxiosResponse<any>>} get
 * @property {(accountId: string|number, gameProfileId: string|number) => Promise<axios.AxiosResponse<any>>} getId
 * @property {(gameProfileId: string|number, options?: { fresh?: boolean }) => Promise<axios.AxiosResponse<any>>} getStatus
 * @property {(accountId: string|number, gameProfileId: string|number, data: Object) => Promise<axios.AxiosResponse<any>>} updateSettings
 * @property {(accountId: string|number, gameProfileId: string|number) => Promise<axios.AxiosResponse<any>>} getDiaryMeta
 * @property {(accountId: string|number, gameProfileId: string|number, params: { period: string, currency: string }) => Promise<axios.AxiosResponse<any>>} getDiarySummary
 * @property {(accountId: string|number, gameProfileId: string|number, params: { period: string, currency: string, page?: number, limit?: number, filter?: string, date?: string }) => Promise<axios.AxiosResponse<any>>} getDiaryEntries
 * @property {(accountId: string|number, gameProfileId: string|number, bannerKey: string) => Promise<axios.AxiosResponse<any>>} getManualPullSuggestions
 * @property {(accountId: string|number, gameProfileId: string|number, data: { banner: string, anchor_id: number|null, name: string, item_id: string|number|null, item_type: string|null, pulled_at: string, pity: number, allow_duplicate: boolean }) => Promise<axios.AxiosResponse<any>>} createManualPull
 * @property {(accountId: string|number, gameProfileId: string|number, pullId: string|number, pity: number|null) => Promise<axios.AxiosResponse<any>>} declarePullPity
 * @property {(accountId: string|number, gameProfileId: string|number, pullId: string|number) => Promise<axios.AxiosResponse<any>>} deleteManualPull
 * @property {(accountId: string|number, gameProfileId: string|number) => Promise<axios.AxiosResponse<any>>} importSRankHistory
 * @property {(accountId: string|number, gameProfileId: string|number, params: { banner?: string, all?: boolean, player?: boolean }) => Promise<axios.AxiosResponse<Blob>>} getPullsCard
 */

/**
 * @typedef {Object} GamesBundleAPI
 * @property {() => GamesAccountsAPI} accounts
 * @property {() => GamesGameProfilesAPI} gameProfiles
 * @property {() => GamesBundleAPI} withErrorHandling
 * @property {(messageData: any) => GamesBundleAPI} withLoadingMessage
 */

/** @type {GamesBundleAPI} */
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
    api._accounts.tokenRelogin = async (accountId, data) => axiosInstance.post(`/accounts/${accountId}/token`, data);
    api._accounts.loginCaptcha = async (data) => axiosInstance.post(`/accounts/login/captcha`, data);
    api._accounts.loginVerifyEmail = async (data) => axiosInstance.post(`/accounts/login/verify-email`, data);
    api._accounts.loginResendEmail = async (data) => axiosInstance.post(`/accounts/login/resend-email`, data);
    api._accounts.getGeetestFrameUrl = (loginSessionId) => `${apiUrl}/accounts/login/geetest-frame?login_session_id=${encodeURIComponent(loginSessionId)}`;

    api._gameProfiles = {};
    api._gameProfiles.get = async (id) => axiosInstance.get(`/accounts/${id}/gameProfiles`);
    api._gameProfiles.getId = async (accountId, gameProfileId) => axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}`);
    api._gameProfiles.updateSettings = async (accountId, gameProfileId, data) => axiosInstance.patch(`/accounts/${accountId}/gameProfiles/${gameProfileId}/settings`, data);
    // Live status, not nested under the account (the profile id is enough). fresh skips the backend
    // caches and is rate limited (429), so the param is sent only when asked for
    api._gameProfiles.getStatus = async (gameProfileId, { fresh = false } = {}) =>
        axiosInstance.get(`/gameProfiles/${gameProfileId}/status`, fresh ? { params: { fresh: 1 } } : undefined);

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
    api._gameProfiles.getPowershellScriptUrl = (gameId) => {
        const url = new URL(`${apiUrl}/pulls/script/powershell`, window.location.origin);
        if (gameId !== undefined && gameId !== null) {
            url.searchParams.set("gameId", String(gameId));
        }
        return url.toString();
    };
    // Multi-file importers receive an array, sent as files[]; single-file ones keep the "file" field
    api._gameProfiles.importBackupPulls = async (accountId, gameProfileId, importerKey, fileOrFiles) => {
        const formData = new FormData();
        formData.append("importer", importerKey);
        if (Array.isArray(fileOrFiles)) {
            fileOrFiles.forEach((file) => formData.append("files[]", file));
        } else {
            formData.append("file", fileOrFiles);
        }
        return axiosInstance.post(
            `/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/import-backup`,
            formData,
            { headers: { "Content-Type": "multipart/form-data" } }
        );
    };
    // Official S-rank history (e.g. HoYoLAB Signal Search): queued in the background, returns the tracked operation
    api._gameProfiles.importSRankHistory = async (accountId, gameProfileId) =>
        axiosInstance.post(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/import-s-rank-history`);
    api._gameProfiles.getPullsOperationStatus = async (accountId, gameProfileId, operationId) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/operations/${encodeURIComponent(operationId)}`);
    api._gameProfiles.getPullsOverview = async (accountId, gameProfileId) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/overview`);
    api._gameProfiles.getPulls = async (accountId, gameProfileId, params) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls`, { params });
    // Shareable card of the pulls (PNG). Errors come back as a Blob too: their problem details are parsed back, so
    // that withErrorHandling() shows them like any other error
    api._gameProfiles.getPullsCard = async (accountId, gameProfileId, params) =>
        axiosInstance
            .get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/card`, { params, responseType: "blob" })
            .catch(async (err) => {
                const data = err.response?.data;
                if (data instanceof Blob) {
                    try {
                        err.response.data = JSON.parse(await data.text());
                    } catch {
                        // Not JSON: left as it is
                    }
                }
                throw err;
            });

    // Manual pulls (missing history): add an earlier S, declare the real pity of an S, delete a manual S
    api._gameProfiles.getManualPullSuggestions = async (accountId, gameProfileId, bannerKey) =>
        axiosInstance.get(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/manual/suggestions`, {
            params: { banner: bannerKey },
        });
    api._gameProfiles.createManualPull = async (accountId, gameProfileId, data) =>
        axiosInstance.post(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/manual`, data);
    // pity = null removes the correction
    api._gameProfiles.declarePullPity = async (accountId, gameProfileId, pullId, pity) =>
        axiosInstance.patch(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/${pullId}/declared-pity`, { pity });
    api._gameProfiles.deleteManualPull = async (accountId, gameProfileId, pullId) =>
        axiosInstance.delete(`/accounts/${accountId}/gameProfiles/${gameProfileId}/pulls/${pullId}`);

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
