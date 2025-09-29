import { LogoutOutlined, MoonOutlined, SunOutlined } from "@ant-design/icons";
import { Avatar, Dropdown, Layout, Menu, theme as antdTheme } from "antd";
import { useThemes } from "@CoreBundle/components/theme/ThemeContext";
import React from "react";

export default function PageLayout({navbarItems = [], dropdownItems = [], childrenPre, children, childrenPost}) {
    const [theme, setTheme] = useThemes();
    const { token: { colorBgContainer } } = antdTheme.useToken();

    return <Layout style={{ height: '100vh' }}>
        {childrenPre}
        <Layout.Header style={{ display: 'flex', alignItems: 'center', background: colorBgContainer }}>
            <Menu
                mode="horizontal"
                // defaultSelectedKeys={['2']} // TODO selected
                style={{ flex: 1, minWidth: 0 }}
                items={navbarItems}
            />
            <Dropdown menu={{
                items: [
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
            }}>
                <Avatar src="https://api.dicebear.com/7.x/miniavs/svg?seed=2"/>
            </Dropdown>
        </Layout.Header>
        <Layout.Content style={{ display: 'flex', maxHeight: '100%' }}>
            {children}
        </Layout.Content>
        {childrenPost}
    </Layout>;

}