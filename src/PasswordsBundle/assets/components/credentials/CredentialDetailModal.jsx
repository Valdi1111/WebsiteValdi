import DeviceCredentialForm from "@PasswordsBundle/components/credentials/DeviceCredentialForm";
import WebsiteCredentialForm from "@PasswordsBundle/components/credentials/WebsiteCredentialForm";
import { useBackendApi } from "@PasswordsBundle/components/BackendApiContext";
import { App, Form, Modal } from "antd";
import React from "react";

export default function CredentialDetailModal({ id, type, open, setOpen }) {
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [loading, setLoading] = React.useState(false);

    const [form] = Form.useForm();
    const { message } = App.useApp();
    const api = useBackendApi();

    React.useEffect(() => {
        if (!open || !id) {
            form.setFieldsValue({ type: type });
            return;
        }
        setLoading(true);
        api.credentials.getId(id).then(
            res => {
                form.setFieldsValue(res.data);
            },
            err => console.error(err),
        ).finally(() => setLoading(false));
    }, [open])

    const credentialType = React.useMemo(() => {
        if (type === 'device') {
            return <DeviceCredentialForm/>;
        }
        if (type === 'website') {
            return <WebsiteCredentialForm/>;
        }
        return null;
    }, [type]);

    const onSubmit = React.useCallback(data => {
        setConfirmLoading(true);
        message.open({
            key: 'credential-saving-loader',
            type: 'loading',
            content: 'Saving credential...',
            duration: 0,
        });
        (id ? api.credentials.edit(id, data) : api.credentials.add(data)).then(
            res => {
                message.open({
                    key: 'credential-saving-loader',
                    type: 'success',
                    content: 'Credential saved successfully',
                    duration: 2.5,
                });
                setOpen(false);
            },
            err => {
                message.destroy('credential-saving-loader');
                console.error(err);
            }
        ).finally(() => {
            setConfirmLoading(false);
        });
    }, [id]);

    return <>
        <Modal
            open={open}
            title={<span>Credential</span>}
            onCancel={() => setOpen(false)}
            destroyOnHidden
            okButtonProps={{
                autoFocus: true,
                htmlType: 'submit',
            }}
            loading={loading}
            confirmLoading={confirmLoading}
            modalRender={(dom) =>
                <Form
                    form={form}
                    layout="vertical"
                    name="form_in_modal"
                    clearOnDestroy={true}
                    onFinish={data => onSubmit({ type, ...data })}>
                    {dom}
                </Form>
            }
            styles={{ body: { overflowY: 'auto', maxHeight: '85vh' } }}
            centered
        >
            {credentialType}
        </Modal>
    </>;

}