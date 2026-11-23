import React from "react";

/**
 * Backend API of the profile page, provided by ProfileApp.
 *
 * @type {React.Context<import("./ProfileApi").ProfileAPI|null>}
 */
export const ProfileApiContext = React.createContext(null);
ProfileApiContext.displayName = 'ProfileApiContext';

/**
 * @returns {import("./ProfileApi").ProfileAPI}
 */
export function useProfileApi() {
    const ctx = React.useContext(ProfileApiContext);
    if (!ctx) {
        throw new Error("useProfileApi must be used inside <ProfileApp>");
    }
    return ctx;
}

export default ProfileApiContext.Provider;
