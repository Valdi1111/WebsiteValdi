import { LogoutOutlined, MoonOutlined, SunOutlined, TeamOutlined, UserOutlined } from "@ant-design/icons";
import { Dropdown, Layout, Menu, theme as antdTheme } from "antd";
import { useThemes } from "@CoreBundle/components/theme/ThemeContext";
import { useCurrentUser } from "@CoreBundle/components/user/CurrentUserContext";
import UserAvatar from "@CoreBundle/components/user/UserAvatar";
import { useLocation } from "react-router";
import React from "react";

// Core page, outside of the bundle apps' routers: a plain link loads it on the current subdomain
const PROFILE_URL = '/profile';
// Core admin page, outside of the bundle apps' routers like the profile
const ADMIN_USERS_URL = '/admin/users';

export default function PageLayout({ navbarItems = [], dropdownItems = [], childrenPre, children, childrenPost }) {
    const { token: { colorBgContainer } } = antdTheme.useToken();
    const location = useLocation();
    const [theme, setTheme] = useThemes();
    const { user } = useCurrentUser();

    const navbarSelectedKeys = React.useMemo(
        () => navbarItems
            .filter(item => item.pathname_regex.test(location.pathname))
            .map(item => item.key.toString()),
        [location]
    );

    const profileItem = {
        key: 'profile',
        label: <a href={PROFILE_URL}>Profile</a>,
        icon: <UserOutlined/>,
    };

    // Only for the admins: the backend refuses the admin pages to the other users anyway
    const adminItems = user?.roles?.includes('ROLE_ADMIN')
        ? [{ key: 'adminUsers', label: <a href={ADMIN_USERS_URL}>Users</a>, icon: <TeamOutlined/> }]
        : [];

    return <Layout style={{ height: '100vh' }}>
        {childrenPre}
        <Layout.Header style={{ display: 'flex', alignItems: 'center', background: colorBgContainer }}>
            <Menu
                mode="horizontal"
                selectedKeys={navbarSelectedKeys}
                style={{ flex: 1, minWidth: 0 }}
                items={navbarItems}
            />
            <Dropdown
                arrow
                menu={{
                    items: [
                        // Who is logged in, once /api/me answered
                        user
                            ? { key: 'account', type: 'group', label: user.email, children: [profileItem, ...adminItems] }
                            : profileItem,
                        {
                            type: 'divider',
                        },
                        ...dropdownItems,
                        {
                            key: 'changeTheme',
                            label: theme === 'light' ? 'Dark theme' : 'Light theme',
                            icon: theme === 'light' ? <MoonOutlined/> : <SunOutlined/>,
                            onClick: () => setTheme(theme => theme === 'light' ? 'dark' : 'light'),
                        },
                        {
                            type: 'divider',
                        },
                        {
                            key: 'signOut',
                            label: <a href="/logout">Sign out</a>,
                            icon: <LogoutOutlined/>,
                            danger: true,
                        },
                    ]
                }}
            >
                <UserAvatar user={user} style={{ cursor: 'pointer' }}/>
            </Dropdown>
        </Layout.Header>
        <Layout.Content style={{ display: 'flex', maxHeight: '100%' }}>
            {children}
        </Layout.Content>
        {childrenPost}
    </Layout>;

}
