import ImageViewModal from "@BooksBundle/components/books/modals/ImageViewModal";
import BookSettingsContext from "@BooksBundle/components/books/BookSettingsContext";
import SpinComponent from "@CoreBundle/components/SpinComponent";
import {
    FONT, FONTS, FONT_SIZE,
    SPACING, MARGINS, WIDTH,
    FORCE_FONT, FORCE_FONT_SIZE, JUSTIFY,
    LAYOUT, LAYOUTS, UPDATE_LAST_READ
} from "@BooksBundle/components/books/BookConstants";
import { Layout } from "antd";
import React from "react";

export default function BookLayout({ children }) {
    const [loading, setLoading] = React.useState(true);
    const [settings, setSettings] = React.useState({});

    // Load settings or set default values
    React.useEffect(() => {
        const s = {};
        loadSettingOrSave(s, FONT, Object.keys(FONTS)[0]);
        loadSettingOrSave(s, FONT_SIZE, 19);
        loadSettingOrSave(s, FORCE_FONT, true);
        loadSettingOrSave(s, FORCE_FONT_SIZE, true);
        loadSettingOrSave(s, SPACING, 1.4);
        loadSettingOrSave(s, MARGINS, 100);
        loadSettingOrSave(s, WIDTH, 1700);
        loadSettingOrSave(s, LAYOUT, Object.keys(LAYOUTS)[0]);
        loadSettingOrSave(s, JUSTIFY, true);
        loadSettingOrSave(s, UPDATE_LAST_READ, true);
        setSettings(s);
        setLoading(false);
    }, []);

    const loadSettingOrSave = React.useCallback((s, key, def) => {
        const value = localStorage.getItem(key);
        if (value != null) {
            s[key] = value;
            return;
        }
        localStorage.setItem(key, def);
        s[key] = def;
    }, []);

    const setSetting = React.useCallback((key, value) => {
        localStorage.setItem(key, value);
        const s = { ...settings };
        s[key] = value;
        setSettings(s);
    }, [settings]);

    // Wait for settings
    return <BookSettingsContext value={{ settings, setSettings, setSetting }}>
        <Layout style={{ height: '100vh' }}>
            <SpinComponent loading={loading} size="large">
                <ImageViewModal/>
                {children}
            </SpinComponent>
        </Layout>
    </BookSettingsContext>;
}
