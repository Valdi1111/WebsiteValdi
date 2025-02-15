import CopyButton from "@PasswordsBundle/components/credential/CopyButton";
import { Button, Form, Input, Space, Tag, Tooltip } from "antd";
import {
    CloseOutlined,
    ExportOutlined,
    GlobalOutlined,
    LockOutlined,
    MailOutlined,
    PlusOutlined,
    SendOutlined,
    UserOutlined
} from "@ant-design/icons";
import React from "react";

export default function WebsiteCredentialForm() {
    const form = Form.useFormInstance();

    return <>
        <Form.Item label="Name" name="name" rules={[{ required: true, message: 'Please input credential name.' }]}>
            <Input prefix={<SendOutlined/>} placeholder="Name"/>
        </Form.Item>
        <Form.List name="websites" initialValue={['']}>
            {(fields, { add, remove }, { errors }) => <>
                {fields.map(({ key, ...field }, index) => <Form.Item
                    label={index === 0 ? 'Website' : ''}
                    key={key}>
                    <Space.Compact block>
                        <Form.Item
                            {...field}
                            rules={[
                                {
                                    required: true,
                                    whitespace: true,
                                    message: "Please input an url or delete this field.",
                                },
                                {
                                    type: 'url',
                                    message: 'The input is not valid url!',
                                    warningOnly: true,
                                },
                            ]}
                            noStyle
                        >
                            <Input prefix={<GlobalOutlined/>} placeholder="Website"/>
                        </Form.Item>
                        <Tooltip title="Open">
                            <Button icon={<ExportOutlined/>} color="primary" variant="outlined" onClick={() => {
                                const url = form.getFieldValue("websites")[field.name];
                                if (!url) {
                                    return;
                                }
                                window.open(url, '_blank').focus();
                            }}
                            />
                        </Tooltip>
                        <Tooltip title="Remove">
                            <Button icon={<CloseOutlined/>} color="danger" variant="outlined"
                                    onClick={() => remove(field.name)}/>
                        </Tooltip>
                    </Space.Compact>
                </Form.Item>)}
                <Form.Item>
                    <Button type="dashed" onClick={() => add()} icon={<PlusOutlined/>} block>Add website</Button>
                    <Form.ErrorList errors={errors}/>
                </Form.Item>
            </>}
        </Form.List>
        <Form.Item label="Username">
            <Space.Compact block>
                <Form.Item name="username" noStyle>
                    <Input prefix={<UserOutlined/>} placeholder="Username"/>
                </Form.Item>
                <CopyButton name="username"/>
            </Space.Compact>
        </Form.Item>
        <Form.Item label="Email">
            <Space.Compact block>
                <Form.Item name="email" noStyle rules={[
                    {
                        type: 'email',
                        message: 'The input is not valid email!',
                        warningOnly: true,
                    },
                ]}>
                    <Input prefix={<MailOutlined/>} placeholder="Email"/>
                </Form.Item>
                <CopyButton name="email"/>
            </Space.Compact>
        </Form.Item>
        <Form.Item label="Password">
            <Space.Compact block>
                <Form.Item name="password" noStyle>
                    <Input.Password prefix={<LockOutlined/>} placeholder="Password"/>
                </Form.Item>
                <CopyButton name="password"/>
            </Space.Compact>
        </Form.Item>
        <Form.Item label="Notes" name="notes">
            <Input.TextArea placeholder="Notes"/>
        </Form.Item>
        <Form.Item label="Recovery codes" name="recovery_codes">
            <Input.TextArea placeholder="Recovery codes"/>
        </Form.Item>
    </>;

}