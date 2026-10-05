import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { FORCE_TEXT_COLOR } from "@BooksBundle/components/books/BookConstants";
import { Checkbox } from "antd";
import React from "react";

export default function SettingsForceTextColor() {
    const { settings, setSetting } = useBookSettings();

    return (
        <Checkbox
            onChange={e => setSetting(FORCE_TEXT_COLOR, e.target.checked ? "true" : "false")}
            checked={settings[FORCE_TEXT_COLOR] === "true"}
        >
            Force color
        </Checkbox>
    );
}
