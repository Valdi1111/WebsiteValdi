import {BrowserRouter, Navigate, Route, Routes} from "react-router-dom";
import HeaderNav from "@CoreBundle/components/HeaderNav";
import HeaderNavItem from "@CoreBundle/components/HeaderNavItem";
import Downloads from "./Downloads";
import * as constants from "../constants";
import React from 'react';

export default function App() {

    return(
        <div className="d-flex flex-column vh-100">
            <BrowserRouter basename={constants.ROOT_URL}>
                <HeaderNav
                    navbar={
                        <HeaderNavItem path="/downloads" name="Downloads"/>
                    }
                    dropdown={
                        <li><span className="dropdown-item cursor-pointer">Prova 1</span></li>
                    }
                />
                <Routes>
                    <Route path="/" element={<Navigate to="/downloads"/>}/>
                    <Route path="/downloads" element={<Downloads/>}/>
                </Routes>
            </BrowserRouter>
        </div>
    );

}