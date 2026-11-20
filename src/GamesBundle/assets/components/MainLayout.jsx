import PageLayout from "@CoreBundle/components/layout/PageLayout";
import { Link, Outlet } from "react-router";
import React from "react";

/**
 * Layout route of the games SPA: one navbar tab per granted provider, page content via <Outlet/>.
 */
export default function MainLayout({ providers }) {
    const navbarItems = React.useMemo(() => providers.map((provider) => ({
        key: provider.key,
        label: <Link to={`/${provider.key}`}>{provider.label}</Link>,
        pathname_regex: new RegExp(`^/${provider.key}(/|$)`),
        icon: provider.icon,
    })), [providers]);

    return <PageLayout navbarItems={navbarItems}>
        <Outlet/>
    </PageLayout>;
}
