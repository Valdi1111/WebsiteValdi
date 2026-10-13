import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { GAP } from "@BooksBundle/components/books/BookConstants";
import { Flex, InputNumber, Typography } from "antd";
import React from "react";

export default function SettingsGap() {
    const { settings, setSetting } = useBookSettings();

    // Default to 6% if not set or invalid
    const currentGap = settings[GAP] !== undefined && settings[GAP] !== null
        ? Number(settings[GAP])
        : 6;

    return (
        <Flex justify="space-between" align="center">
            <Typography.Text>Column gap</Typography.Text>
            <InputNumber
                style={{ width: 160 }}
                onChange={val => setSetting(GAP, val)}
                value={currentGap}
                formatter={value => `${value}%`}
                parser={value => value.replace('%', '')}
                step={1}
                min={0}
                max={25}
            />
        </Flex>
    );
}
