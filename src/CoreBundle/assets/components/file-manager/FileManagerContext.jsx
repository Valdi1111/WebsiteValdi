import React from "react";

/**
 * @typedef {Object} FileManagerContext
 * @property {FileManagerAPI} [api]
 * @property {Object|null} [info]
 * @property {(data: Object|null) => void} [setInfo]
 * @property {string|null} [selectedFolder]
 * @property {(id: string|null) => void} [setSelectedFolder]
 * @property {string|null} [selectedFile]
 * @property {(key: string|null) => void} [setSelectedFile]
 * @property {Object|null} [clipboard]
 * @property {(data: Object|null) => void} [setClipboard]
 * @property {Array.<Object>} [folders]
 * @property {() => Promise<axios.AxiosResponse<any>>} [reloadFolders]
 * @property {Array.<Object>} [files]
 * @property {() => Promise<axios.AxiosResponse<any>>} [reloadFiles]
 * @property {boolean} [filesLoading]
 */

/**
 * Create a context typed as API.
 * The initial value is cast to `any` because React requires a default,
 * but the real value will be provided by the Provider at runtime.
 *
 * @type {React.Context<FileManagerContext>}
 */
export const FileManagerContext = React.createContext({});
FileManagerContext.displayName = 'FileManagerContext';

/**
 * Custom hook to access the backend API context.
 * This ensures that the returned value is correctly typed as API.
 *
 * @returns {FileManagerContext} The backend API object with all methods
 */
export function useFileManager() {
    const ctx = React.useContext(FileManagerContext);
    if (!ctx) {
        throw new Error("useFileManager must be used inside <FileManager>");
    }
    return ctx;
}

/**
 * Export the Provider so that the API can be injected
 * into the component tree at the top level
 */
export default FileManagerContext.Provider;