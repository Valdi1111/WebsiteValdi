import PageLayout from "@CoreBundle/components/layout/PageLayout";
import {FolderOpenOutlined, UserSwitchOutlined} from "@ant-design/icons";
import { Link } from "react-router";
import React from "react";

export default function MainLayout({ children }) {

    return <PageLayout
        navbarItems={[
            {
                key: 1,
                label: <Link to="/hoyoverse">Hoyoverse</Link>,
                pathname_regex: /^\/hoyoverse/,
                icon: <UserSwitchOutlined />
            }
        ]}
        children={children}
    />;

}