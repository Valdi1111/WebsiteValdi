import React from "react";

export const ThemeContext = React.createContext([]);
ThemeContext.displayName = 'ThemeContext';

export function useThemes() {
    return React.useContext(ThemeContext);
}


export const ShelvesContext = React.createContext([]);
ShelvesContext.displayName = 'ShelvesContext';

export function useShelves() {
    return React.useContext(ShelvesContext);
}


export const BookSettingsContext = React.createContext([]);
BookSettingsContext.displayName = 'BookSettingsContext';

export function useBookSettings() {
    return React.useContext(BookSettingsContext);
}