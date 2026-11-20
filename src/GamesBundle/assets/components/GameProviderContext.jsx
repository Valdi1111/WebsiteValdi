import React from "react";

/**
 * Descriptor of the current game provider (see each provider bundle's assets/provider.js),
 * extended with `basePath` (e.g. "/hoyoverse"). Provided by ProviderScope.
 */
export const GameProviderContext = React.createContext(null);
GameProviderContext.displayName = 'GameProviderContext';

/**
 * @returns {{ key: string, label: string, basePath: string } & Object} The current game provider
 */
export function useGameProvider() {
    const ctx = React.useContext(GameProviderContext);
    if (!ctx) {
        throw new Error("useGameProvider must be used inside <ProviderScope>");
    }
    return ctx;
}

export default GameProviderContext.Provider;
