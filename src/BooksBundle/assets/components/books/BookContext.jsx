import React from "react";

export const BookContext = React.createContext([]);
BookContext.displayName = 'BookContext';

export function useBook() {
    return React.useContext(BookContext);
}

export default BookContext.Provider;