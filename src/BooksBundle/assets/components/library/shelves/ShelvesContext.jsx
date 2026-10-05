import React from "react";

/**
 * @typedef {Object} Shelf
 * @property {string|number} id
 * @property {string} name
 * @property {string} path
 * @property {int|null} library_id
 * @property {int} books_count
 * @property {string} created
 */

/**
 * @typedef {Object} ShelvesContext
 * @property {boolean} shelvesLoading - Indicates whether the shelves list is currently loading.
 * @property {() => Promise<void>} refreshShelves - Fetches and reloads the list of shelves from the API.
 * @property {Shelf[]} shelves - Array of fetched shelves.
 * @property {boolean} contentLoading - Indicates whether the selected shelf's content is loading.
 * @property {() => Promise<void>} refreshContent - Fetches and reloads books/sub-shelves for the selected shelf.
 * @property {Array<any>} content - Content or sub-shelves belonging to the currently selected shelf.
 * @property {React.Dispatch<React.SetStateAction<Array<any>>>} setContent - State setter for the shelf content.
 * @property {Shelf|null} selectedShelf - Currently selected shelf, or null if none is selected.
 * @property {React.Dispatch<React.SetStateAction<Shelf|null>>} setSelectedShelf - State setter for the selected shelf.
 * @property {boolean} collapsed - Collapse state of the sidebar.
 * @property {React.Dispatch<React.SetStateAction<boolean>>} setCollapsed - State setter for toggling sidebar collapse.
 */

/**
 * React Context for managing shelves and their content state.
 *
 * @type {React.Context<ShelvesContext>}
 */
export const ShelvesContext = React.createContext(/** @type {ShelvesContext} */ ({}));
ShelvesContext.displayName = 'ShelvesContext';

/**
 * Custom hook to access the shelves context.
 *
 * @returns {ShelvesContext} The context object containing state and dispatch methods.
 * @throws {Error} If called outside of a LibraryShelvesId provider.
 */
export function useShelves() {
    const ctx = React.useContext(ShelvesContext);
    if (!ctx) {
        throw new Error("useShelves must be used inside <LibraryShelvesId>");
    }
    return ctx;
}

/**
 * Export the Provider so shelves state can be injected into the component tree.
 */
export default ShelvesContext.Provider;
