import PageLayout from "@CoreBundle/components/layout/PageLayout";
import { Link } from "react-router-dom";
import React from "react";

export default function MainLayout({children}) {

    return <PageLayout
        navbarItems={[
            {
                key: 1,
                label: <Link to="/files">Files</Link>,
            },
        ]}
        children={children}
    />;

}