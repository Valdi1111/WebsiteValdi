import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { FONT_SIZE, FONT_SIZES } from "@BooksBundle/components/books/BookConstants";
import { Flex, Select, Typography } from "antd";
import React from "react";

const options = FONT_SIZES.map(i => ({ value: i, label: i }));

export default function SettingsFontSize() {
    const { settings, setSetting } = useBookSettings();

    return <Flex justify="space-between" align="center">
        <Typography.Text>Font size</Typography.Text>
        <Select
            style={{ width: 160 }}
            onChange={val => setSetting(FONT_SIZE, val)}
            value={settings[FONT_SIZE]}
            options={options}
            optionRender={option => <span style={{ fontSize: option.value }}>{option.label}</span>}
        />
    </Flex>
}
