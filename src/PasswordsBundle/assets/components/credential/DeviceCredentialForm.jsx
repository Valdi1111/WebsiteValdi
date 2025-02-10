import CopyButton from "@PasswordsBundle/components/credential/CopyButton";
import { Form, Input, Space } from "antd";
import {
    ApiOutlined,
    ClusterOutlined,
    LockOutlined,
    SendOutlined,
    UserOutlined
} from "@ant-design/icons";
import React from "react";

export default function DeviceCredentialForm() {

    return <>
        <Form.Item label="Name" name="name" rules={[{ required: true, message: 'Please input credential name.' }]}>
            <Input prefix={<SendOutlined/>} placeholder="Name"/>
        </Form.Item>
        <Form.Item label="Device">
            <Space.Compact block>
                <Form.Item name="ip" noStyle>
                    <Input prefix={<ApiOutlined/>} placeholder="Ip"/>
                </Form.Item>
                <CopyButton name="ip"/>
                <Form.Item name="port" noStyle>
                    <Input prefix={<ClusterOutlined/>} placeholder="Port"/>
                </Form.Item>
                <CopyButton name="port"/>
            </Space.Compact>
        </Form.Item>
        <Form.Item label="Username">
            <Space.Compact block>
                <Form.Item name="username" noStyle>
                    <Input prefix={<UserOutlined/>} placeholder="Username"/>
                </Form.Item>
                <CopyButton name="username"/>
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
    </>;

}