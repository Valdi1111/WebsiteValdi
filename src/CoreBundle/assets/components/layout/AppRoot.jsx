import { THEMES, THEME } from "@CoreBundle/components/theme/ThemeConstants";
import ThemeContext from "@CoreBundle/components/theme/ThemeContext";
import { App, ConfigProvider } from "antd";
import React from "react";

export default function AppRoot({ children }) {
    const [theme, setTheme] = React.useState(localStorage.getItem(THEME) || Object.keys(THEMES)[0]);

    // Load theme or set default
    React.useEffect(() => {
        if (!theme) {
            return;
        }
        localStorage.setItem(THEME, theme);
        const elems = document.getElementsByTagName('html')
        if (!elems || !elems.length) {
            return;
        }
        elems[0].setAttribute('data-bs-theme', theme);
        // update webix theme
        for (const link of document.getElementsByTagName('link')) {
            if (link.rel === 'stylesheet' && /\/bundles\/core\/(docmanager|filemanager|gantt|scheduler|webix)\/skins\/\w+.css/.test(link.getAttribute('href'))) {
                link.setAttribute('href', link.getAttribute('href').replace(/\w+.css/, THEMES[theme].webix));
            }
        }
    }, [theme]);

    return <ThemeContext value={[theme, setTheme]}>
        <ConfigProvider theme={{ algorithm: THEMES[theme].antd }}>
            <App>
                {children}
            </App>
        </ConfigProvider>
    </ThemeContext>;
}
