import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { WIDTH } from "@BooksBundle/components/books/BookConstants";
import { Flex, InputNumber, Typography } from "antd";
import React from "react";

export default function SettingsWidth() {
    const { settings, setSetting } = useBookSettings();

    return <Flex justify="space-between" align="center">
        <Typography.Text>Width</Typography.Text>
        <InputNumber
            style={{ width: 160 }}
            onChange={val => setSetting(WIDTH, val)}
            value={settings[WIDTH]}
            mode="spinner"
            step={100}
            min={0}
        />
    </Flex>
}
