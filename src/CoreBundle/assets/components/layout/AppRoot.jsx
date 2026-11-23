import { THEMES, THEME } from "@CoreBundle/components/theme/ThemeConstants";
import ThemeContext from "@CoreBundle/components/theme/ThemeContext";
import CurrentUserContext from "@CoreBundle/components/user/CurrentUserContext";
import useCurrentUserLoader from "@CoreBundle/components/user/useCurrentUserLoader";
import { App, ConfigProvider } from "antd";
import React from "react";

export default function AppRoot({ children }) {
    const [theme, setTheme] = React.useState(localStorage.getItem(THEME) || Object.keys(THEMES)[0]);
    const currentUser = useCurrentUserLoader();

    // Load theme or set default
    React.useEffect(() => {
        if (!theme) {
            return;
        }
        localStorage.setItem(THEME, theme);
    }, [theme]);

    return <ThemeContext value={[theme, setTheme]}>
        <CurrentUserContext value={currentUser}>
            <ConfigProvider theme={{ algorithm: THEMES[theme].antd }}>
                <App>
                    {children}
                </App>
            </ConfigProvider>
        </CurrentUserContext>
    </ThemeContext>;
}
