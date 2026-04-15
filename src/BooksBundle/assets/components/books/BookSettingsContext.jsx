import React from "react";

export const BookSettingsContext = React.createContext({});
BookSettingsContext.displayName = 'BookSettingsContext';

export function useBookSettings() {
    return React.useContext(BookSettingsContext);
}

export default BookSettingsContext.Provider;