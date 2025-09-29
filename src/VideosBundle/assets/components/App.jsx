import AppLayoutRouter from "@CoreBundle/components/layout/AppLayoutRouter";
import FileManager from "@CoreBundle/components/FileManager";
import VideoPlayer from "@CoreBundle/components/VideoPlayer";
import MainLayout from "@VideosBundle/components/MainLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import BackendApiContext from "@VideosBundle/components/BackendApiContext";
import createBackendApi from "@VideosBundle/components/BackendApi";
import { API_FILES_URL, API_URL, ROOT_URL } from "@VideosBundle/constants";
import { Navigate, Route } from "react-router-dom";
import React from "react";

export default function App() {
    const api = React.useMemo(() => createBackendApi(API_URL), []);

    function videosApiUrl(path) {
        return `${API_FILES_URL}/direct?id=${encodeURIComponent(path)}`;
    }

    return <BackendApiContext value={api}>
        <AppLayoutRouter rootUrl={ROOT_URL}>
            <Route path="/" element={<Navigate to="/files"/>}/>
            <Route path="/files" element={
                <MainLayout>
                    <FileManager apiUrl={API_FILES_URL + "/"}/>
                </MainLayout>
            }/>
            <Route path="/videos" element={<VideoPlayer apiUrl={videosApiUrl}/>}/>
            <Route path="*" element={
                <MainLayout>
                    <NotFoundComponent redirectPath="/files" redirectText="Back Home"/>
                </MainLayout>
            }/>
        </AppLayoutRouter>
    </BackendApiContext>;

}