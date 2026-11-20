import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import createBackendApi from "@GamesBundle/components/BackendApi";
import BackendApiContext from "@GamesBundle/components/BackendApiContext";
import GameProviderContext from "@GamesBundle/components/GameProviderContext";
import { Route, Routes } from "react-router";
import { App as AntdApp } from "antd";
import React from "react";

/**
 * Everything under /{provider.key}/*: provides the provider's backend API and descriptor
 * and renders its pages. Must be rendered with key={provider.key} so that switching tab
 * remounts the whole subtree instead of reusing the previous provider's state.
 */
export default function ProviderScope({ provider }) {
    const app = AntdApp.useApp();
    const api = React.useMemo(() => createBackendApi(`/api/${provider.key}`, app), [provider.key, app]);
    const providerValue = React.useMemo(() => ({ ...provider, basePath: `/${provider.key}` }), [provider]);
    const { AccountsPage, PullsPage, extraRoutes = [] } = provider;

    return <GameProviderContext value={providerValue}>
        <BackendApiContext value={api}>
            <Routes>
                <Route index element={<AccountsPage/>}/>
                <Route path="accounts/:accountId/profiles/:gameProfileId/pulls" element={<PullsPage/>}/>
                {extraRoutes.map((route) => (
                    <Route key={route.path} path={route.path} element={route.element}/>
                ))}
                <Route path="*" element={
                    <NotFoundComponent redirectPath={providerValue.basePath} redirectText={`Back to ${provider.label}`}/>
                }/>
            </Routes>
        </BackendApiContext>
    </GameProviderContext>;
}
