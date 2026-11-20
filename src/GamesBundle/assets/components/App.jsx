import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import MainLayout from "@GamesBundle/components/MainLayout";
import ProviderScope from "@GamesBundle/components/ProviderScope";
import { BrowserRouter, Navigate, Route, Routes } from "react-router";
import { Empty, Flex } from "antd";
import React from "react";

/**
 * @param {{ providers: Array<Object> }} props granted provider descriptors, in tab order
 */
export default function App({ providers }) {
    return <BrowserRouter>
        <Routes>
            <Route element={<MainLayout providers={providers}/>}>
                <Route index element={providers.length > 0
                    ? <Navigate to={`/${providers[0].key}`} replace/>
                    : <NoProviders/>
                }/>
                {providers.map((provider) => (
                    <Route key={provider.key} path={`${provider.key}/*`} element={
                        <ProviderScope key={provider.key} provider={provider}/>
                    }/>
                ))}
                <Route path="*" element={<NotFoundComponent redirectPath="/" redirectText="Back Home"/>}/>
            </Route>
        </Routes>
    </BrowserRouter>;
}

function NoProviders() {
    return <Flex align="center" justify="center" style={{ width: '100%' }}>
        <Empty description="No games available for your account"/>
    </Flex>;
}
