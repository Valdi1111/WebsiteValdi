import PageLayout from "@CoreBundle/components/layout/PageLayout";
import { AppstoreOutlined } from "@ant-design/icons";
import { Link } from "react-router";
import React from "react";

export default function MainLayout({ children }) {
    return (
        <PageLayout
            navbarItems={[
                {
                    key: 1,
                    label: <Link to="/gryphline">Gryphline</Link>,
                    pathname_regex: /^\/gryphline/,
                    icon: <AppstoreOutlined />
                }
            ]}
        >
            {children}
        </PageLayout>
    );
}
