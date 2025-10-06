import CredentialsTable from "@PasswordsBundle/components/credentials/CredentialsTable";
import MainLayout from "@PasswordsBundle/components/MainLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import BackendApiContext from "@PasswordsBundle/components/BackendApiContext";
import createBackendApi from "@PasswordsBundle/components/BackendApi";
import { API_URL, ROOT_URL } from "@PasswordsBundle/constants";
import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import { App as AntdApp } from "antd";
import React from "react";

export default function App() {
    const app = AntdApp.useApp();
    const api = React.useMemo(() => createBackendApi(API_URL, app), []);

    return <BackendApiContext value={api}>
        <BrowserRouter basename={ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/credentials/all"/>}/>
                <Route path="/credentials/all" element={
                    <MainLayout>
                        <CredentialsTable/>
                    </MainLayout>
                }/>
                <Route path="*" element={
                    <MainLayout>
                        <NotFoundComponent redirectPath="/credentials/all" redirectText="Back Home"/>
                    </MainLayout>
                }/>
            </Routes>
        </BrowserRouter>
    </BackendApiContext>;

}