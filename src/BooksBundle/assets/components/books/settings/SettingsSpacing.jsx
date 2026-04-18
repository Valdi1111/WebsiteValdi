import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { SPACING } from "@BooksBundle/components/books/BookConstants";
import { Flex, InputNumber, Typography } from "antd";
import React from "react";

export default function SettingsSpacing() {
    const { settings, setSetting } = useBookSettings();

    return <Flex justify="space-between" align="center">
        <Typography.Text>Line spacing</Typography.Text>
        <InputNumber
            style={{ width: 160 }}
            onChange={val => setSetting(SPACING, val)}
            value={settings[SPACING]}
            mode="spinner"
            step={0.05}
            min={1}
        />
    </Flex>
}
