import CredentialsTable from "@PasswordsBundle/components/credentials/CredentialsTable";
import AppLayoutRouter from "@CoreBundle/components/layout/AppLayoutRouter";
import MainLayout from "@PasswordsBundle/components/MainLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import BackendApiContext from "@PasswordsBundle/components/BackendApiContext";
import createBackendApi from "@PasswordsBundle/components/BackendApi";
import { API_URL, ROOT_URL } from "@PasswordsBundle/constants";
import { Navigate, Route } from "react-router-dom";
import React from "react";

export default function App() {
    const api = React.useMemo(() => createBackendApi(API_URL), []);

    return <BackendApiContext value={api}>
        <AppLayoutRouter rootUrl={ROOT_URL}>
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
        </AppLayoutRouter>
    </BackendApiContext>;

}