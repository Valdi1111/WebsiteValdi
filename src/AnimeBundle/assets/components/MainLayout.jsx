import PageLayout from "@CoreBundle/components/layout/PageLayout";
import { Link } from "react-router-dom";
import React from "react";

export default function MainLayout({children}) {

    return <PageLayout
        navbarItems={[
            {
                key: 1,
                label: <Link to="/downloads">Downloads</Link>,
            },
            {
                key: 2,
                label: <Link to="/season-folders">Season Folders</Link>,
            },
            {
                key: 3,
                label: <Link to="/list-anime">List Anime</Link>,
            },
            {
                key: 4,
                label: <Link to="/list-manga">List Manga</Link>,
            },
            {
                key: 5,
                label: <Link to="/files">Files</Link>,
            },
        ]}
        children={children}
    />;

}