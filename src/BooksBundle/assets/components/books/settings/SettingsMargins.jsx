import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { MARGINS } from "@BooksBundle/components/books/BookConstants";
import { Flex, InputNumber, Typography } from "antd";
import React from "react";

export default function SettingsMargins() {
    const { settings, setSetting } = useBookSettings();

    return <Flex justify="space-between" align="center">
        <Typography.Text>Margins</Typography.Text>
        <InputNumber
            style={{ width: 160 }}
            onChange={val => setSetting(MARGINS, val)}
            value={settings[MARGINS]}
            mode="spinner"
            step={20}
            min={0}
        />
    </Flex>
}
