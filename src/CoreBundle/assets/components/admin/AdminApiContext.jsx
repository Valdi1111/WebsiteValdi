import React from "react";

/**
 * Backend API of the admin pages, provided by AdminApp.
 *
 * @type {React.Context<import("./AdminApi").AdminAPI|null>}
 */
export const AdminApiContext = React.createContext(null);
AdminApiContext.displayName = 'AdminApiContext';

/**
 * @returns {import("./AdminApi").AdminAPI}
 */
export function useAdminApi() {
    const ctx = React.useContext(AdminApiContext);
    if (!ctx) {
        throw new Error("useAdminApi must be used inside <AdminApp>");
    }
    return ctx;
}

export default AdminApiContext.Provider;
