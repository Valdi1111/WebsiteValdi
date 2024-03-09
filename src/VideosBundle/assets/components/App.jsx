import {BrowserRouter, Navigate, Route, Routes} from "react-router-dom";
import MainLayout from "@VideosBundle/components/MainLayout";
import FileManager from "@CoreBundle/components/FileManager";
import VideoPlayer from "@CoreBundle/components/VideoPlayer";
import * as constants from "@VideosBundle/constants";
import React from 'react';

export default function App() {

    function videosApiUrl(path) {
        return `/api/fileManager/direct?id=${encodeURIComponent(path)}`;
    }

    return (
        <BrowserRouter basename={constants.ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/files"/>}/>
                <Route path="/files" element={<MainLayout><FileManager apiUrl="/api/fileManager/"/></MainLayout>}/>
                <Route path="/videos" element={<VideoPlayer apiUrl={videosApiUrl}/>}/>
            </Routes>
        </BrowserRouter>
    );

}