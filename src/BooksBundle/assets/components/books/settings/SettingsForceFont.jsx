import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { FORCE_FONT } from "@BooksBundle/components/books/BookConstants";
import { Checkbox } from "antd";
import React from "react";

export default function SettingsForceFont() {
    const { settings, setSetting } = useBookSettings();

    return <Checkbox
        onChange={e => setSetting(FORCE_FONT, e.target.checked ? "true" : "false")}
        checked={settings[FORCE_FONT] === "true"}
    >Force font</Checkbox>;
}
