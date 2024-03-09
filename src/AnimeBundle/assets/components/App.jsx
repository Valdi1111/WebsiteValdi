import {BrowserRouter, Navigate, Route, Routes} from "react-router-dom";
import MainLayout from "@AnimeBundle/components/MainLayout";
import Downloads from "@AnimeBundle/components/Downloads";
import FileManager from "@CoreBundle/components/FileManager";
import VideoPlayer from "@CoreBundle/components/VideoPlayer";
import * as constants from "@AnimeBundle/constants";
import React from 'react';

export default function App() {

    function videosApiUrl(path) {
        return `/api/fileManager/direct?id=${encodeURIComponent(path)}`;
    }

    return (
        <BrowserRouter basename={constants.ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/downloads"/>}/>
                <Route path="/downloads" element={<MainLayout><Downloads/></MainLayout>}/>
                <Route path="/files" element={<MainLayout><FileManager apiUrl="/api/fileManager/"/></MainLayout>}/>
                <Route path="/videos" element={<VideoPlayer apiUrl={videosApiUrl}/>}/>
            </Routes>
        </BrowserRouter>
    );

}