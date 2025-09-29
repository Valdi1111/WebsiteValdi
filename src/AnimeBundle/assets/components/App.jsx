import AppLayoutRouter from "@CoreBundle/components/layout/AppLayoutRouter";
import FileManager from "@CoreBundle/components/file-manager/FileManager";
import VideoPlayer from "@CoreBundle/components/VideoPlayer";
import DownloadsTable from "@AnimeBundle/components/downloads/DownloadsTable";
import SeasonFoldersTable from "@AnimeBundle/components/season-folders/SeasonFoldersTable";
import ListAnimeTable from "@AnimeBundle/components/list-anime/ListAnimeTable";
import ListMangaTable from "@AnimeBundle/components/list-manga/ListMangaTable";
import MainLayout from "@AnimeBundle/components/MainLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import BackendApiContext from "@AnimeBundle/components/BackendApiContext";
import createBackendApi from "@AnimeBundle/components/BackendApi";
import { API_FILES_URL, API_URL, ROOT_URL } from "@AnimeBundle/constants";
import { Navigate, Route } from "react-router-dom";
import React from "react";

export default function App() {
    const api = React.useMemo(() => createBackendApi(API_URL), []);

    function videosApiUrl(path) {
        return `${API_FILES_URL}/direct?id=${encodeURIComponent(path)}`;
    }

    return <BackendApiContext value={api}>
        <AppLayoutRouter rootUrl={ROOT_URL}>
            <Route path="/" element={<Navigate to="/downloads"/>}/>
            <Route path="/downloads" element={
                <MainLayout>
                    <DownloadsTable/>
                </MainLayout>
            }/>
            <Route path="/season-folders" element={
                <MainLayout>
                    <SeasonFoldersTable/>
                </MainLayout>
            }/>
            <Route path="/list-anime" element={
                <MainLayout>
                    <ListAnimeTable/>
                </MainLayout>
            }/>
            <Route path="/list-manga" element={
                <MainLayout>
                    <ListMangaTable/>
                </MainLayout>
            }/>
            <Route path="/files" element={
                <MainLayout>
                    <FileManager apiUrl={API_FILES_URL}/>
                </MainLayout>
            }/>
            <Route path="/videos" element={
                <VideoPlayer apiUrl={videosApiUrl}/>
            }/>
            <Route path="*" element={
                <MainLayout>
                    <NotFoundComponent redirectPath="/downloads" redirectText="Back Home"/>
                </MainLayout>
            }/>
        </AppLayoutRouter>
    </BackendApiContext>;

}