import {BrowserRouter, Navigate, Route, Routes} from "react-router-dom";
import Videos from "./Videos";
import Files from "./Files";
import * as constants from "../constants";
import React from 'react';

export default function App() {

    return (
        <BrowserRouter basename={constants.ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/files"/>}/>
                <Route path="/files" element={<Files/>}/>
                <Route path="/videos" element={<Videos/>}/>
            </Routes>
        </BrowserRouter>
    );

}