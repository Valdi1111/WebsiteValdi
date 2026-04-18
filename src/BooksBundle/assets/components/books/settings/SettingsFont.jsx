import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { FONT, FONTS } from "@BooksBundle/components/books/BookConstants";
import { Flex, Select, Typography } from "antd";
import React from "react";

const options = Object
    .entries(FONTS)
    .map(([id, value]) => ({ value: id, label: value }));

export default function SettingsFont() {
    const { settings, setSetting } = useBookSettings();

    return <Flex justify="space-between" align="center">
        <Typography.Text>Font</Typography.Text>
        <Select
            style={{ width: 160 }}
            onChange={val => setSetting(FONT, val)}
            value={settings[FONT]}
            options={options}
            optionRender={option => <span style={{ fontFamily: option.value }}>{option.label}</span>}
            showSearch
        />
    </Flex>
}
