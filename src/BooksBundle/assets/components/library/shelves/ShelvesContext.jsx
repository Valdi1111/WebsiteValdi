import axios from "axios";
import React from "react";

/**
 * @typedef {Object} ShelvesContext
 * @property {boolean} [shelvesLoading]
 * @property {() => Promise<axios.AxiosResponse<any>>} [reloadShelves]
 * @property {array} [shelves]
 * @property {boolean} [contentLoading]
 * @property {() => Promise<axios.AxiosResponse<any>>} [reloadContent]
 * @property {array} [content]
 * @property {Object} [selectedShelf]
 * @property {(shelf: Object) => void} [setSelectedShelf]
 * @property {boolean} [collapsed]
 * @property {(collapsed: boolean) => void} [setCollapsed]
 */

/**
 * Create a context typed as ShelvesContext.
 * The initial value is cast to `any` because React requires a default,
 * but the real value will be provided by the Provider at runtime.
 *
 * @type {React.Context<ShelvesContext>}
 */
export const ShelvesContext = React.createContext({});
ShelvesContext.displayName = 'ShelvesContext';

/**
 * Custom hook to access the shelves' context.
 * This ensures that the returned value is correctly typed as ShelvesContext.
 *
 * @returns {ShelvesContext} The shelves object with all methods
 */
export function useShelves() {
    const ctx = React.useContext(ShelvesContext);
    if (!ctx) {
        throw new Error("useShelves must be used inside <LibraryShelvesId>");
    }
    return ctx;
}

/**
 * Export the Provider so that the shelves can be injected
 * into the component tree at the top level
 */
export default ShelvesContext.Provider;