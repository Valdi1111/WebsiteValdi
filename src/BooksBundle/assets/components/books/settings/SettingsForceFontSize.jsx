import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { FORCE_FONT_SIZE } from "@BooksBundle/components/books/BookConstants";
import { Checkbox } from "antd";
import React from "react";

export default function SettingsForceFontSize() {
    const { settings, setSetting } = useBookSettings();

    return <Checkbox
        onChange={e => setSetting(FORCE_FONT_SIZE, e.target.checked ? "true" : "false")}
        checked={settings[FORCE_FONT_SIZE] === "true"}
    >Force font size</Checkbox>;
}
