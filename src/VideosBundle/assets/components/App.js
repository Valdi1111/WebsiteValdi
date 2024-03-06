import {BrowserRouter, Navigate, Route, Routes} from "react-router-dom";
import FileManager from "@VideosBundle/components/FileManager";
import Videos from "@VideosBundle/components/Videos";
import * as constants from "../constants";
import React from 'react';

export default function App() {

    return (
        <BrowserRouter basename={constants.ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/files"/>}/>
                <Route path="/files" element={<FileManager/>}/>
                <Route path="/videos" element={<Videos/>}/>
            </Routes>
        </BrowserRouter>
    );

}