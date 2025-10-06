import FileManager from "@CoreBundle/components/FileManager";
import VideoPlayer from "@CoreBundle/components/VideoPlayer";
import MainLayout from "@VideosBundle/components/MainLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import BackendApiContext from "@VideosBundle/components/BackendApiContext";
import createBackendApi from "@VideosBundle/components/BackendApi";
import { API_URL, ROOT_URL } from "@VideosBundle/constants";
import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import { App as AntdApp } from "antd";
import React from "react";

export default function App() {
    const app = AntdApp.useApp();
    const api = React.useMemo(() => createBackendApi(API_URL, app), []);

    return <BackendApiContext value={api}>
        <BrowserRouter basename={ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/files"/>}/>
                <Route path="/files" element={
                    <MainLayout>
                        <FileManager apiUrl={api.fmUrl() + "/"}/>
                    </MainLayout>
                }/>
                <Route path="/videos" element={<VideoPlayer apiUrl={api.fmDirectUrl}/>}/>
                <Route path="*" element={
                    <MainLayout>
                        <NotFoundComponent redirectPath="/files" redirectText="Back Home"/>
                    </MainLayout>
                }/>
            </Routes>
        </BrowserRouter>
    </BackendApiContext>;

}