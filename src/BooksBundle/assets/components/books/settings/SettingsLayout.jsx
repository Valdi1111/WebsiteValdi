import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { LAYOUT, LAYOUTS } from "@BooksBundle/components/books/BookConstants";
import { Radio } from "antd";
import React from "react";

const options = Object
    .entries(LAYOUTS)
    .map(([id, { name }]) => ({ value: id, label: name }));

export default function SettingsLayout() {
    const { settings, setSetting } = useBookSettings();

    return (
        <Radio.Group
            style={{ width: 160 }}
            // Ant Design Radio.Group provides an event object with target.value
            onChange={e => setSetting(LAYOUT, e.target.value)}
            value={settings[LAYOUT]}
            options={options}
            vertical
        />
    );
}
