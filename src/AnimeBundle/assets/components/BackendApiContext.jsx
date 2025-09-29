import React from "react";

/**
 * Create a context typed as API.
 * The initial value is cast to `any` because React requires a default,
 * but the real value will be provided by the Provider at runtime.
 *
 * @type {React.Context<AnimeBundleAPI>}
 */
export const BackendApiContext = React.createContext({});
BackendApiContext.displayName = 'BackendApiContext';

/**
 * Custom hook to access the backend API context.
 * This ensures that the returned value is correctly typed as API.
 *
 * @returns {AnimeBundleAPI} The backend API object with all methods
 */
export function useBackendApi() {
    const ctx = React.useContext(BackendApiContext);
    if (!ctx) {
        throw new Error("useBackendApi must be used inside <App>");
    }
    return ctx;
}

/**
 * Export the Provider so that the API can be injected
 * into the component tree at the top level
 */
export default BackendApiContext.Provider;