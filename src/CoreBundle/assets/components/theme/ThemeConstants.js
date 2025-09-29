// Theme constants
import { theme } from "antd";
export const THEME = 'theme';

export const THEMES = {
    light: {
        name: 'Light',
        webix: 'material.css',
        antd: theme.defaultAlgorithm,
    },
    dark: {
        name: 'Dark',
        webix: 'dark.css',
        antd: theme.darkAlgorithm,
    },
}
