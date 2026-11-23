import { DeleteOutlined, EyeInvisibleOutlined, EyeOutlined, PlusOutlined } from "@ant-design/icons";
import { useProfileApi } from "@CoreBundle/components/profile/ProfileApiContext";
import LocalTable from "@CoreBundle/components/standard-table/LocalTable";
import { Alert, App, Button, Flex, Form, Input, Modal, Popconfirm, Switch, Typography } from "antd";
import React from "react";

// Same as Token::DESCRIPTION_MAX_LENGTH in the backend
const DESCRIPTION_MAX_LENGTH = 255;

const STATUS_CATALOG = {
    active: { text: 'Active', color: 'success' },
    disabled: { text: 'Disabled', color: 'default' },
};

/**
 * The value of a token, asked for to the backend only when the user wants to see it.
 */
function TokenValue({ id }) {
    const api = useProfileApi();
    const [value, setValue] = React.useState(null);
    const [loading, setLoading] = React.useState(false);

    const show = () => {
        setLoading(true);
        api.withErrorHandling().getToken(id)
            .then(res => setValue(res.data.token))
            .catch(() => {})
            .finally(() => setLoading(false));
    };

    if (value === null) {
        return <Button size="small" icon={<EyeOutlined/>} loading={loading} onClick={show}>Show</Button>;
    }

    return <Flex align="center" gap="small">
        <Typography.Text code copyable ellipsis style={{ maxWidth: 260 }}>{value}</Typography.Text>
        <Button size="small" type="text" icon={<EyeInvisibleOutlined/>} onClick={() => setValue(null)} aria-label="Hide"/>
    </Flex>;
}

/**
 * Creation of a token: its description, then its value, ready to copy.
 */
function CreateTokenModal({ open, onClose, onCreated }) {
    const api = useProfileApi();
    const [created, setCreated] = React.useState(null);
    const [saving, setSaving] = React.useState(false);

    const create = ({ description }) => {
        setSaving(true);
        api.withErrorHandling().createToken(description.trim())
            .then(res => {
                setCreated(res.data);
                onCreated(res.data);
            })
            .catch(() => {})
            .finally(() => setSaving(false));
    };

    const close = () => {
        setCreated(null);
        onClose();
    };

    return <Modal title="New token" open={open} onCancel={close} footer={null} destroyOnHidden>
        {created
            ? <Flex vertical gap="middle">
                <Alert
                    type="success"
                    showIcon
                    title="Token created"
                    description={<>Send it in the <Typography.Text code>Authorization: Bearer &lt;token&gt;</Typography.Text> header. You can see it again from this page.</>}
                />
                <Typography.Text code copyable>{created.token}</Typography.Text>
                <Button type="primary" onClick={close}>Done</Button>
            </Flex>
            : <Form layout="vertical" onFinish={create} requiredMark={false}>
                <Form.Item
                    name="description"
                    label="Description"
                    extra="What uses it, e.g. a script or a device."
                    rules={[
                        { required: true, whitespace: true, message: 'Describe what the token is for.' },
                        { max: DESCRIPTION_MAX_LENGTH },
                    ]}
                >
                    <Input maxLength={DESCRIPTION_MAX_LENGTH} autoFocus/>
                </Form.Item>
                <Button type="primary" htmlType="submit" loading={saving}>Create</Button>
            </Form>}
    </Modal>;
}

/**
 * "Tokens" tab of the profile: the API tokens of the user, active and disabled. They can be created, shown, renamed,
 * enabled or disabled and deleted (/api/me/tokens).
 */
export default function TokensPage() {
    const api = useProfileApi();
    const [tokens, setTokens] = React.useState([]);
    const [loading, setLoading] = React.useState(true);
    const [creating, setCreating] = React.useState(false);

    const load = React.useCallback(() => {
        setLoading(true);
        api.withErrorHandling().getTokens()
            .then(res => setTokens(res.data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [api]);

    React.useEffect(load, [load]);

    const replace = (token) => setTokens(list => list.map(t => t.id === token.id ? token : t));

    const update = (token, changes, messages) => api.withLoadingMessage({ key: `token-${token.id}`, ...messages })
        .updateToken(token.id, changes)
        .then(res => replace(res.data))
        .catch(() => {});

    const remove = (token) => api.withLoadingMessage({
        key: `token-${token.id}`,
        loadingContent: 'Deleting the token...',
        successContent: 'Token deleted',
    }).deleteToken(token.id)
        .then(() => setTokens(list => list.filter(t => t.id !== token.id)))
        .catch(() => {});

    const rows = React.useMemo(
        () => tokens.map(token => ({ ...token, status: token.valid ? 'active' : 'disabled' })),
        [tokens]
    );

    const columns = React.useMemo(() => [
        {
            title: 'Description',
            dataIndex: 'description',
            render: (description, token) => <Typography.Text
                type={description ? undefined : 'secondary'}
                editable={{
                    text: description ?? '',
                    maxLength: DESCRIPTION_MAX_LENGTH,
                    tooltip: 'Edit the description',
                    onChange: (value) => {
                        const trimmed = value.trim();
                        if (trimmed && trimmed !== description) {
                            update(token, { description: trimmed }, {
                                loadingContent: 'Saving the description...',
                                successContent: 'Description saved',
                            });
                        }
                    },
                }}
            >
                {description ?? 'No description'}
            </Typography.Text>,
        },
        {
            title: 'Token',
            key: 'token',
            dataIndex: 'id',
            render: (id) => <TokenValue id={id}/>,
        },
        {
            title: 'Status',
            dataIndex: 'status',
            filterType: 'tags',
            tagCatalog: STATUS_CATALOG,
            width: 140,
            render: (_, token) => <Switch
                checked={token.valid}
                checkedChildren="Active"
                unCheckedChildren="Disabled"
                onChange={(valid) => update(token, { valid }, {
                    loadingContent: valid ? 'Enabling the token...' : 'Disabling the token...',
                    successContent: valid ? 'Token enabled' : 'Token disabled',
                })}
            />,
        },
        {
            title: '',
            key: 'actions',
            width: 60,
            align: 'right',
            render: (_, token) => <Popconfirm
                title="Delete this token?"
                description="What uses it can no longer log in."
                okText="Delete"
                okButtonProps={{ danger: true }}
                onConfirm={() => remove(token)}
            >
                <Button danger type="text" icon={<DeleteOutlined/>} aria-label="Delete"/>
            </Popconfirm>,
        },
    ], [api]);

    return <Flex vertical gap="middle" style={{ width: '100%', maxWidth: 960, margin: '0 auto', padding: 24, overflowY: 'auto' }}>
        <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
            Scripts and services log in with a token in the <Typography.Text code>Authorization: Bearer &lt;token&gt;</Typography.Text> header.
            A disabled token stops working until you enable it again. Changing the password does not affect the tokens.
        </Typography.Paragraph>
        <LocalTable
            rowKey="id"
            title="API tokens"
            columns={columns}
            dataSource={rows}
            loading={loading}
            pagination={false}
            onReload={load}
            extraToolbarActions={<Button type="primary" icon={<PlusOutlined/>} onClick={() => setCreating(true)}>New token</Button>}
        />
        <CreateTokenModal
            open={creating}
            onClose={() => setCreating(false)}
            onCreated={(token) => setTokens(list => [...list, { ...token, token: null }])}
        />
    </Flex>;
}
