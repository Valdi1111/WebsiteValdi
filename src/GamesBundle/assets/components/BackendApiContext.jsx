import React from "react";

/**
 * Backend API of the current game provider. Provided by ProviderScope; `null` outside of it
 * so that useBackendApi() fails loudly instead of returning an empty object.
 *
 * @type {React.Context<GamesBundleAPI|null>}
 */
export const BackendApiContext = React.createContext(null);
BackendApiContext.displayName = 'BackendApiContext';

/**
 * @returns {GamesBundleAPI} The backend API of the current game provider
 */
export function useBackendApi() {
    const ctx = React.useContext(BackendApiContext);
    if (!ctx) {
        throw new Error("useBackendApi must be used inside <ProviderScope>");
    }
    return ctx;
}

/**
 * Export the Provider so that the API can be injected
 * into the component tree at the top level
 */
export default BackendApiContext.Provider;
