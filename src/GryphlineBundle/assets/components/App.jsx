import AccountsPage from "@GryphlineBundle/components/account/AccountsPage";
import MainLayout from "@GryphlineBundle/components/MainLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import BackendApiContext from "@GryphlineBundle/components/BackendApiContext";
import createBackendApi from "@GryphlineBundle/components/BackendApi";
import { API_URL, ROOT_URL } from "@GryphlineBundle/constants";
import { BrowserRouter, Navigate, Route, Routes } from "react-router";
import { App as AntdApp } from "antd";
import React from "react";

export default function App() {
    const app = AntdApp.useApp();
    const api = React.useMemo(() => createBackendApi(API_URL, app), [app]);

    return (
        <BackendApiContext value={api}>
            <BrowserRouter basename={ROOT_URL}>
                <Routes>
                    <Route path="/" element={<Navigate to="/gryphline" />} />
                    <Route path="/gryphline" element={
                        <MainLayout>
                            <AccountsPage />
                        </MainLayout>
                    } />
                    <Route path="*" element={
                        <MainLayout>
                            <NotFoundComponent redirectPath="/gryphline" redirectText="Back Home" />
                        </MainLayout>
                    } />
                </Routes>
            </BrowserRouter>
        </BackendApiContext>
    );
}
