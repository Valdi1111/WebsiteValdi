import LoadingComponent from "../LoadingComponent";
import ImageViewModal from "./modals/ImageViewModal";
import {
    FONT, FONTS, FONT_SIZE,
    SPACING, MARGINS, WIDTH,
    FORCE_FONT, FORCE_FONT_SIZE, JUSTIFY,
    LAYOUT, LAYOUTS, UPDATE_LAST_READ
} from "./BookConstants";
import {BookSettingsContext} from "../Contexts";
import React from "react";

export default function BookLayout({children}) {
    const [settings, setSettings] = React.useState({});
    const [readySettings, setReadySettings] = React.useState(false);

    // Load settings or set defaults
    React.useEffect(() => {
        const s = {};
        getSettingOrSave(s, FONT, Object.keys(FONTS)[0]);
        getSettingOrSave(s, FONT_SIZE, 19);
        getSettingOrSave(s, FORCE_FONT, true);
        getSettingOrSave(s, FORCE_FONT_SIZE, true);
        getSettingOrSave(s, SPACING, 1.4);
        getSettingOrSave(s, MARGINS, 100);
        getSettingOrSave(s, WIDTH, 1700);
        getSettingOrSave(s, LAYOUT, Object.keys(LAYOUTS)[0]);
        getSettingOrSave(s, JUSTIFY, true);
        getSettingOrSave(s, UPDATE_LAST_READ, true);
        setSettings(s);
        setReadySettings(true);
    }, []);

    function getSettingOrSave(s, key, def) {
        const value = localStorage.getItem(key);
        if (value != null) {
            s[key] = value;
            return;
        }
        localStorage.setItem(key, def);
        s[key] = def;
    }

    function setSetting(key, value) {
        localStorage.setItem(key, value);
        const s = settings;
        s[key] = value;
        setSettings({...s});
    }

    // Wait for settings
    if (!readySettings) {
        return (<LoadingComponent/>);
    }

    return (
        <>
            <ImageViewModal/>
            <BookSettingsContext.Provider value={[settings, setSetting]}>
                <div className="vw-100 vh-100 d-flex flex-column">
                    {children}
                </div>
            </BookSettingsContext.Provider>
        </>
    );
}
