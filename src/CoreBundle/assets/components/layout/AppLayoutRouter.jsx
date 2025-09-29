import AppLayout from "@CoreBundle/components/layout/AppLayout";
import { BrowserRouter, Routes } from "react-router-dom";
import React from "react";

export default function AppLayoutRouter({ children, rootUrl }) {

    return <AppLayout>
        <BrowserRouter basename={rootUrl}>
            <Routes>
                {children}
            </Routes>
        </BrowserRouter>
    </AppLayout>;

}
