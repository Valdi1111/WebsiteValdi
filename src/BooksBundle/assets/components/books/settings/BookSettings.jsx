import SettingsFont from "@BooksBundle/components/books/settings/SettingsFont";
import SettingsFontSize from "@BooksBundle/components/books/settings/SettingsFontSize";
import SettingsForceFont from "@BooksBundle/components/books/settings/SettingsForceFont";
import SettingsForceFontSize from "@BooksBundle/components/books/settings/SettingsForceFontSize";
import SettingsFullJustification from "@BooksBundle/components/books/settings/SettingsFullJustification";
import SettingsLayouts from "@BooksBundle/components/books/settings/SettingsLayouts";
import SettingsMargins from "@BooksBundle/components/books/settings/SettingsMargins";
import SettingsSpacing from "@BooksBundle/components/books/settings/SettingsSpacing";
import SettingsWidth from "@BooksBundle/components/books/settings/SettingsWidth";
import { useBook } from "@BooksBundle/components/books/BookContext";
import { SettingOutlined } from "@ant-design/icons";
import { Button, Drawer } from "antd";
import React from "react";

export default function BookSettings() {
    const { settingsDrawerOpen, setSettingsDrawerOpen } = useBook();

    return <>
        <Button
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
            <SettingsFont/>
            <SettingsFontSize/>
            <SettingsSpacing/>
            <SettingsMargins/>
            <SettingsWidth/>
            <SettingsForceFont/>
            <SettingsForceFontSize/>
            <SettingsFullJustification/>
            <SettingsLayouts/>
        </Drawer>
    </>;
}
