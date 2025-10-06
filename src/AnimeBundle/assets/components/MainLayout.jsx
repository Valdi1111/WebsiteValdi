import PageLayout from "@CoreBundle/components/layout/PageLayout";
import { DownloadOutlined, FolderOpenOutlined, FolderOutlined, UnorderedListOutlined } from "@ant-design/icons";
import { Link } from "react-router-dom";
import React from "react";

export default function MainLayout({ children }) {

    return <PageLayout
        navbarItems={[
            {
                key: 1,
                label: <Link to="/downloads">Downloads</Link>,
                pathnameregex: /^\/downloads$/,
                icon: <DownloadOutlined/>
            },
            {
                key: 2,
                label: <Link to="/season-folders">Season Folders</Link>,
                pathnameregex: /^\/season-folders$/,
                icon: <FolderOutlined/>
            },
            {
                key: 3,
                label: <Link to="/list-anime">List Anime</Link>,
                pathnameregex: /^\/list-anime$/,
                icon: <UnorderedListOutlined/>
            },
            {
                key: 4,
                label: <Link to="/list-manga">List Manga</Link>,
                pathnameregex: /^\/list-manga$/,
                icon: <UnorderedListOutlined/>
            },
            {
                key: 5,
                label: <Link to="/files">Files</Link>,
                pathnameregex: /^\/files$/,
                icon: <FolderOpenOutlined/>
            },
        ]}
        children={children}
    />;

}