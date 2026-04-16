import PageLayout from "@CoreBundle/components/layout/PageLayout";
import { KeyOutlined } from "@ant-design/icons";
import { Link } from "react-router";
import React from "react";

export default function MainLayout({ children }) {

    return <PageLayout
        navbarItems={[
            {
                key: 1,
                label: <Link to="/credentials/all">Credentials</Link>,
                pathname_regex: /^\/credentials\/all$/,
                icon: <KeyOutlined/>,
            },
        ]}
        children={children}
    />;

}