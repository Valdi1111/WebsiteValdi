import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { LAYOUT, LAYOUTS } from "@BooksBundle/components/books/BookConstants";
import { Radio } from "antd";
import React from "react";

const options = Object
    .entries(LAYOUTS)
    .map(([id, { name }]) => ({ value: id, label: name }));

export default function SettingsLayout() {
    const { settings, setSetting } = useBookSettings();

    return <Radio.Group
        style={{ width: 160 }}
        onChange={val => setSetting(LAYOUT, val[0])}
        value={settings[LAYOUT]}
        options={options}
        vertical
    />;
}
