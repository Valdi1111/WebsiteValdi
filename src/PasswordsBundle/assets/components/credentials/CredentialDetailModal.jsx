import DeviceCredentialForm from "@PasswordsBundle/components/credentials/DeviceCredentialForm";
import WebsiteCredentialForm from "@PasswordsBundle/components/credentials/WebsiteCredentialForm";
import { useBackendApi } from "@PasswordsBundle/components/BackendApiContext";
import { Form, Modal } from "antd";
import React from "react";

export default function CredentialDetailModal({ id, type, open, setOpen }) {
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [loading, setLoading] = React.useState(false);

    const [form] = Form.useForm();
    const api = useBackendApi();

    React.useEffect(() => {
        if (!open || !id) {
            form.setFieldsValue({ type: type });
            return;
        }
        setLoading(true);
        api
            .withErrorHandling()
            .credentials()
            .getId(id)
            .then(res => {
                form.setFieldsValue(res.data);
            })
            .finally(() => setLoading(false));
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
        const credentialsApi = api
            .withLoadingMessage({
                key: 'credential-saving-loader',
                loadingContent: 'Saving credential...',
                successContent: 'Credential saved successfully',
            })
            .credentials();
        (id ? credentialsApi.edit(id, data) : credentialsApi.add(data))
            .then(res => {
                setOpen(false);
            })
            .finally(() => setConfirmLoading(false));
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
                    name="credential_detail_modal"
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