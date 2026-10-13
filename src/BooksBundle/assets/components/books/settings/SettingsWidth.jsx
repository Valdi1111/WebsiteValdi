import { useBookSettings } from "@BooksBundle/components/books/BookSettingsContext";
import { WIDTH } from "@BooksBundle/components/books/BookConstants";
import { Flex, InputNumber, Typography } from "antd";
import React from "react";

export default function SettingsWidth() {
    const { settings, setSetting } = useBookSettings();

    const currentWidth = settings[WIDTH] ? Number(settings[WIDTH]) : 1400;

    return (
        <Flex justify="space-between" align="center">
            <Typography.Text>Width</Typography.Text>
            <InputNumber
                style={{ width: 160 }}
                onChange={val => setSetting(WIDTH, val)}
                value={currentWidth}
                formatter={value => `${value}px`}
                parser={value => value.replace('px', '')}
                step={50}
                min={500}
                max={3000}
            />
        </Flex>
    );
}
