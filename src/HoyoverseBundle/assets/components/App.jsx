import AccountsPage from "@HoyoverseBundle/components/account/AccountsPage";
import DiaryPage from "@HoyoverseBundle/components/diary/DiaryPage";
import MainLayout from "@HoyoverseBundle/components/MainLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import BackendApiContext from "@HoyoverseBundle/components/BackendApiContext";
import createBackendApi from "@HoyoverseBundle/components/BackendApi";
import { API_URL, ROOT_URL } from "@HoyoverseBundle/constants";
import { BrowserRouter, Navigate, Route, Routes } from "react-router";
import { App as AntdApp } from "antd";
import React from "react";

export default function App() {
    const app = AntdApp.useApp();
    const api = React.useMemo(() => createBackendApi(API_URL, app), [app]);

    return <BackendApiContext value={api}>
        <BrowserRouter basename={ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/hoyoverse"/>}/>
                <Route path="/hoyoverse" element={
                    <MainLayout>
                        <AccountsPage/>
                    </MainLayout>
                }/>
                <Route path="/hoyoverse/accounts/:accountId/profiles/:gameProfileId/diary" element={
                    <MainLayout>
                        <DiaryPage/>
                    </MainLayout>
                }/>
                <Route path="*" element={
                    <MainLayout>
                        <NotFoundComponent redirectPath="/hoyoverse" redirectText="Back Home"/>
                    </MainLayout>
                }/>
            </Routes>
        </BrowserRouter>
    </BackendApiContext>;
}