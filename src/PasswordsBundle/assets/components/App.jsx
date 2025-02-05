import {BrowserRouter, Navigate, Route, Routes} from "react-router-dom";
import * as constants from "@PasswordsBundle/constants";
import React from 'react';

export default function App() {

    return (
        <BrowserRouter basename={constants.ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/files"/>}/>
                <Route path="/files" element={<p>asdasd</p>}/>
            </Routes>
        </BrowserRouter>
    );

}