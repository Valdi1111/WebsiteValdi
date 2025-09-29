import React from "react";

export const ThemeContext = React.createContext([]);
ThemeContext.displayName = 'ThemeContext';

export function useThemes() {
    return React.useContext(ThemeContext);
}

export default ThemeContext.Provider;