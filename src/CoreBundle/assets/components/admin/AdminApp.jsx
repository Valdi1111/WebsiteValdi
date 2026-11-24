import { HomeOutlined, TeamOutlined } from "@ant-design/icons";
import createAdminApi from "@CoreBundle/components/admin/AdminApi";
import AdminApiContext from "@CoreBundle/components/admin/AdminApiContext";
import UsersPage from "@CoreBundle/components/admin/UsersPage";
import PageLayout from "@CoreBundle/components/layout/PageLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import { App as AntdApp } from "antd";
import { BrowserRouter, Link, Navigate, Outlet, Route, Routes } from "react-router";
import React from "react";

const NAVBAR_ITEMS = [
    {
        key: 'home',
        // The app of the current subdomain, outside of this router
        label: <a href="/">Home</a>,
        pathname_regex: /^\/$/,
        icon: <HomeOutlined/>,
    },
    {
        key: 'users',
        label: <Link to="/admin/users">Users</Link>,
        pathname_regex: /^\/admin\/users$/,
        icon: <TeamOutlined/>,
    },
];

/**
 * Admin app of the "admin" entry (AdminController serves every /admin/* path to the admins).
 */
export default function AdminApp() {
    const app = AntdApp.useApp();
    const api = React.useMemo(() => createAdminApi(app), []);

    return <AdminApiContext value={api}>
        <BrowserRouter>
            <Routes>
                <Route element={<PageLayout navbarItems={NAVBAR_ITEMS}><Outlet/></PageLayout>}>
                    {/* Users is the only admin page for now */}
                    <Route path="/admin" element={<Navigate to="/admin/users" replace/>}/>
                    <Route path="/admin/users" element={<UsersPage/>}/>
                    <Route path="*" element={<NotFoundComponent redirectPath="/admin/users" redirectText="Back to users"/>}/>
                </Route>
            </Routes>
        </BrowserRouter>
    </AdminApiContext>;
}
