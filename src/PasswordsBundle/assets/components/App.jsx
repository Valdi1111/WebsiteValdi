import CredentialsList from "@PasswordsBundle/components/CredentialsList";
import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import { App as AntdApp, ConfigProvider, theme } from "antd";
import * as constants from "@PasswordsBundle/constants";
import React from 'react';

export default function App() {

    return <ConfigProvider theme={{ algorithm: theme.defaultAlgorithm }}>
        <AntdApp>
            <BrowserRouter basename={constants.ROOT_URL}>
                <Routes>
                    <Route path="/" element={<Navigate to="/credentials/all"/>}/>
                    <Route path="/credentials/all" element={<CredentialsList/>}/>
                </Routes>
            </BrowserRouter>
        </AntdApp>
    </ConfigProvider>;

}