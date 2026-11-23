import { HomeOutlined, UserOutlined } from "@ant-design/icons";
import PageLayout from "@CoreBundle/components/layout/PageLayout";
import NotFoundComponent from "@CoreBundle/components/NotFoundComponent";
import createProfileApi from "@CoreBundle/components/profile/ProfileApi";
import ProfileApiContext from "@CoreBundle/components/profile/ProfileApiContext";
import ProfilePage from "@CoreBundle/components/profile/ProfilePage";
import { App as AntdApp } from "antd";
import { BrowserRouter, Link, Outlet, Route, Routes } from "react-router";
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
        key: 'profile',
        label: <Link to="/profile">Profile</Link>,
        pathname_regex: /^\/profile$/,
        icon: <UserOutlined/>,
    },
];

/**
 * Profile app of the "core" entry. PageLayout reads the location, so it needs a router even for one page.
 */
export default function ProfileApp() {
    const app = AntdApp.useApp();
    const api = React.useMemo(() => createProfileApi(app), []);

    return <ProfileApiContext value={api}>
        <BrowserRouter>
            <Routes>
                <Route element={<PageLayout navbarItems={NAVBAR_ITEMS}><Outlet/></PageLayout>}>
                    <Route path="/profile" element={<ProfilePage/>}/>
                    <Route path="*" element={<NotFoundComponent redirectPath="/profile" redirectText="Back to profile"/>}/>
                </Route>
            </Routes>
        </BrowserRouter>
    </ProfileApiContext>;
}
