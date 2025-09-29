import React from "react";

export const FileManagerContext = React.createContext({});
FileManagerContext.displayName = 'FileManagerContext';

export function useFileManager() {
    const ctx = React.useContext(FileManagerContext);
    if (!ctx) {
        throw new Error("useFileManager must be used inside <FileManager>");
    }
    return ctx;
}

export default FileManagerContext.Provider;