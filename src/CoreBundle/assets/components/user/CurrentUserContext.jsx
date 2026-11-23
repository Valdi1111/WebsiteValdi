import React from "react";

/**
 * Logged-in user, as returned by GET /api/me.
 *
 * @typedef {Object} CurrentUser
 * @property {number} id
 * @property {string} email
 * @property {string[]} roles assigned roles and those inherited through the role hierarchy, as the backend checks them
 */

/**
 * @typedef {Object} CurrentUserState
 * @property {CurrentUser|null} user null until the first successful load (e.g. it stays null on an expired session);
 *     a failed reload keeps the last loaded user
 * @property {boolean} loading
 * @property {any} error the last failed request, null after a successful one
 * @property {() => Promise<void>} reload loads the user again, e.g. after it was changed
 */

/** @type {React.Context<CurrentUserState>} */
export const CurrentUserContext = React.createContext({
    user: null,
    loading: false,
    error: null,
    reload: async () => {},
});
CurrentUserContext.displayName = 'CurrentUserContext';

/**
 * @returns {CurrentUserState} The logged-in user, loaded once by AppRoot
 */
export function useCurrentUser() {
    return React.useContext(CurrentUserContext);
}

export default CurrentUserContext.Provider;
