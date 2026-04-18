import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { JUSTIFY } from "@BooksBundle/components/books/BookConstants";
import { Checkbox } from "antd";
import React from "react";

export default function SettingsFullJustification() {
    const { settings, setSetting } = useBookSettings();

    return <Checkbox
        onChange={e => setSetting(JUSTIFY, e.target.checked ? "true" : "false")}
        checked={settings[JUSTIFY] === "true"}
    >Force font</Checkbox>;
}
