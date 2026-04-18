import SettingsFont from "@BooksBundle/components/books/settings/SettingsFont";
import SettingsFontSize from "@BooksBundle/components/books/settings/SettingsFontSize";
import SettingsForceFont from "@BooksBundle/components/books/settings/SettingsForceFont";
import SettingsForceFontSize from "@BooksBundle/components/books/settings/SettingsForceFontSize";
import SettingsFullJustification from "@BooksBundle/components/books/settings/SettingsFullJustification";
import SettingsSpacing from "@BooksBundle/components/books/settings/SettingsSpacing";
import SettingsMargins from "@BooksBundle/components/books/settings/SettingsMargins";
import SettingsWidth from "@BooksBundle/components/books/settings/SettingsWidth";
import SettingsLayout from "@BooksBundle/components/books/settings/SettingsLayout";
import { useBook } from "@BooksBundle/components/books/BookContext";
import { SettingOutlined } from "@ant-design/icons";
import { Button, Divider, Drawer, Flex } from "antd";
import React from "react";

export default function BookSettings() {
    const { settingsDrawerOpen, setSettingsDrawerOpen } = useBook();

    return <>
        <Button
            style={{ flexShrink: 0 }}
            color="default"
            variant="filled"
            icon={<SettingOutlined/>}
            onClick={() => setSettingsDrawerOpen(current => !current)}
        />
        <Drawer
            title="Settings"
            placement="right"
            closable={{ placement: 'end' }}
            onClose={() => setSettingsDrawerOpen(false)}
            open={settingsDrawerOpen}
            key="book-settings-drawer"
        >
            <Flex vertical gap="medium">
                <Divider titlePlacement="start" style={{ margin: 0 }}>Typography / Text</Divider>
                <SettingsFont/>
                <SettingsFontSize/>
                <SettingsForceFont/>
                <SettingsForceFontSize/>
                <SettingsFullJustification/>
                <SettingsSpacing/>
                <Divider titlePlacement="start" style={{ margin: 0 }}>Layout / Page Style</Divider>
                <SettingsMargins/>
                <SettingsWidth/>
                <Divider titlePlacement="start" style={{ margin: 0 }}>Reading Mode / Pagination</Divider>
                <SettingsLayout/>
            </Flex>
        </Drawer>
    </>;
}
