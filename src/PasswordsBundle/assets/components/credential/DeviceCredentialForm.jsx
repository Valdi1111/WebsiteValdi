import CopyButton from "@PasswordsBundle/components/credential/CopyButton";
import { Form, Input, Space, Tag } from "antd";
import {
    ApiOutlined,
    ClusterOutlined, GlobalOutlined,
    LockOutlined, PlusOutlined,
    SendOutlined,
    UserOutlined
} from "@ant-design/icons";
import React from "react";

export default function DeviceCredentialForm() {
    const [tagInputVisible, setTagInputVisible] = React.useState(false);
    const tagInputRef = React.useRef();
    const form = Form.useFormInstance();

    return <>
        <Form.List name="tags" initialValue={[]}>
            {(fields, { add, remove }, { errors }) => <Space>
                {fields.map(({ key, ...field }, index) =>
                    <Tag key={key} color="green" closable onClose={() => remove(field.name)}>
                        {form.getFieldValue("tags")[field.name]}
                    </Tag>
                )}

                {tagInputVisible ? (
                    <Input
                        ref={tagInputRef}
                        type="text"
                        size="small"
                        // style={tagInputStyle}
                        onBlur={(e) => {
                            add(e.target.value);
                        }}
                        onPressEnter={(e) => {
                            add(e.target.value);
                        }}
                    />
                ) : (
                    <Tag style={{ height: 22, borderStyle: 'dashed' }} icon={<PlusOutlined/>}
                         onClick={() => {
                             setTagInputVisible(true);
                             tagInputRef.current.focus();
                         }}>
                        New Tag
                    </Tag>
                )}
                <Form.ErrorList errors={errors}/>
            </Space>}
        </Form.List>
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