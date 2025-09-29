import React from "react";

export const ShelvesContext = React.createContext([]);
ShelvesContext.displayName = 'ShelvesContext';

export function useShelves() {
    return React.useContext(ShelvesContext);
}