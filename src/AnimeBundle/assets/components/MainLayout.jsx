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
                pathname_regex: /^\/downloads$/,
                icon: <DownloadOutlined/>
            },
            {
                key: 2,
                label: <Link to="/season-folders">Season Folders</Link>,
                pathname_regex: /^\/season-folders$/,
                icon: <FolderOutlined/>
            },
            {
                key: 3,
                label: <Link to="/list-anime">List Anime</Link>,
                pathname_regex: /^\/list-anime$/,
                icon: <UnorderedListOutlined/>
            },
            {
                key: 4,
                label: <Link to="/list-manga">List Manga</Link>,
                pathname_regex: /^\/list-manga$/,
                icon: <UnorderedListOutlined/>
            },
            {
                key: 5,
                label: <Link to="/files">Files</Link>,
                pathname_regex: /^\/files$/,
                icon: <FolderOpenOutlined/>
            },
        ]}
        children={children}
    />;

}