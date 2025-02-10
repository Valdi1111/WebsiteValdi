import { App, Button, Form } from "antd";
import { CopyOutlined } from "@ant-design/icons";
import React from "react";

export default function CopyButton({ name }) {
    const form = Form.useFormInstance();
    const { message } = App.useApp();

    const copy = React.useCallback((e) => {
        const value = form.getFieldValue(name);
        if (!value) {
            message.warning("Empty value!");
            return;
        }
        navigator.clipboard.writeText(value);
        message.success("Copied successfully!");
    }, [form, name]);

    return <Button icon={<CopyOutlined/>} onClick={copy}/>;

}