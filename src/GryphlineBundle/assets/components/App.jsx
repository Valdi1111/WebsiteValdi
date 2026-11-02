import MainLayout from "@GryphlineBundle/components/MainLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import { ROOT_URL } from "@GryphlineBundle/constants";
import { BrowserRouter, Navigate, Route, Routes } from "react-router";
import { Card, Typography } from "antd";
import React from "react";

export default function App() {
    return (
        <BrowserRouter basename={ROOT_URL}>
            <Routes>
                <Route path="/" element={<Navigate to="/gryphline" />} />
                <Route path="/gryphline" element={
                    <MainLayout>
                        <div style={{ padding: 24, width: '100%' }}>
                            <Card title="Gryphline">
                                <Typography.Paragraph>
                                    Welcome to Gryphline! The bundle skeleton is ready.
                                </Typography.Paragraph>
                            </Card>
                        </div>
                    </MainLayout>
                } />
                <Route path="*" element={
                    <MainLayout>
                        <NotFoundComponent redirectPath="/gryphline" redirectText="Back Home" />
                    </MainLayout>
                } />
            </Routes>
        </BrowserRouter>
    );
}
