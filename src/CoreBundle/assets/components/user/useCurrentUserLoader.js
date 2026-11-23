import axios from "axios";
import React from "react";

// Core route without host: the same URL on every subdomain
export const CURRENT_USER_URL = '/api/me';

/**
 * Loads the logged-in user once, for the CurrentUserContext provided by AppRoot.
 * A failed request (e.g. 401 on an expired session) leaves the last loaded user in place
 * and is only logged: every page of every bundle runs it, so it must never break them.
 *
 * @returns {import("./CurrentUserContext").CurrentUserState}
 */
export default function useCurrentUserLoader() {
    const [state, setState] = React.useState({ user: null, loading: true, error: null });

    const reload = React.useCallback(() => {
        setState(state => ({ ...state, loading: true }));

        return axios.get(CURRENT_USER_URL).then(
            res => setState({ user: res.data, loading: false, error: null }),
            err => {
                console.warn('Could not load the current user:', err.response?.status ?? err.message);
                setState(state => ({ ...state, loading: false, error: err }));
            },
        );
    }, []);

    // For the answers that already carry the updated user (e.g. the avatar upload): no second request
    const setUser = React.useCallback(user => setState({ user, loading: false, error: null }), []);

    React.useEffect(() => {
        reload();
    }, [reload]);

    return React.useMemo(() => ({ ...state, reload, setUser }), [state, reload, setUser]);
}
