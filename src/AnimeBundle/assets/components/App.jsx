import {BrowserRouter, Navigate, Route, Routes} from "react-router-dom";
import TableDownloads from "@AnimeBundle/components/TableDownloads";
import TableSeasonFolders from "@AnimeBundle/components/TableSeasonFolders";
import TableListAnime from "@AnimeBundle/components/TableListAnime";
import TableListManga from "@AnimeBundle/components/TableListManga";
import MainLayout from "@AnimeBundle/components/MainLayout";
import FileManager from "@CoreBundle/components/FileManager";
import VideoPlayer from "@CoreBundle/components/VideoPlayer";
import * as constants from "@AnimeBundle/constants";
import React from 'react';

export default function App() {

    function videosApiUrl(path) {
        return `/api/files/direct?id=${encodeURIComponent(path)}`;
    }

    return (
        <BrowserRouter basename={constants.ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/downloads"/>}/>
                <Route path="/downloads" element={<MainLayout><TableDownloads/></MainLayout>}/>
                <Route path="/season/folders" element={<MainLayout><TableSeasonFolders/></MainLayout>}/>
                <Route path="/list/anime" element={<MainLayout><TableListAnime/></MainLayout>}/>
                <Route path="/list/manga" element={<MainLayout><TableListManga/></MainLayout>}/>
                <Route path="/files" element={<MainLayout><FileManager apiUrl="/api/files/"/></MainLayout>}/>
                <Route path="/videos" element={<VideoPlayer apiUrl={videosApiUrl}/>}/>
            </Routes>
        </BrowserRouter>
    );

}