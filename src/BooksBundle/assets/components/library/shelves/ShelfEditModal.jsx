import { useShelves } from "@BooksBundle/components/library/shelves/ShelvesContext";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { Form, Input, Modal } from "antd";
import React from "react";

/**
 * Modal
 * @param {boolean} visible
 * @param {(visible: boolean) => void} setVisible
 * @returns {JSX.Element}
 * @constructor
 */
export default function ShelfEditModal({ visible, setVisible }) {
    const [loading, setLoading] = React.useState(true);
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [form] = Form.useForm();

    const { selectedShelf, refreshShelves } = useShelves();
    const api = useBackendApi();

    const afterOpenChange = React.useCallback((opened) => {
        setLoading(true);
        if (!opened) {
            return;
        }
        form.setFieldsValue(selectedShelf);
        setLoading(false);
    }, [selectedShelf]);

    const onShelfEdit = React.useCallback((data) => {
        if (!selectedShelf) {
            return;
        }
        setConfirmLoading(true);
        api
            .withLoadingMessage({
                key: 'shelf-edit-loader',
                loadingContent: 'Updating shelf...',
                successContent: 'Shelf updated successfully',
            })
            .shelves()
            .edit(selectedShelf.id, data.name)
            .then(res => {
                refreshShelves();
                setVisible(false);
            })
            .finally(() => setConfirmLoading(false));
    }, [selectedShelf?.id]);

    return <Modal
        open={visible}
        afterOpenChange={afterOpenChange}
        title={<span>Edit shelf</span>}
        onCancel={() => setVisible(false)}
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
                name="edit_shelf_modal"
                clearOnDestroy={true}
                onFinish={(data) => onShelfEdit(data)}>
                {dom}
            </Form>
        }
    >
        <Form.Item
            label="Path"
            name="path"
            rules={[
                {
                    required: true,
                    message: 'Please input the shelf path!',
                },
            ]}>
            <Input placeholder="Shelf path" disabled={true}/>
        </Form.Item>
        <Form.Item
            label="Name"
            name="name"
            rules={[
                {
                    required: true,
                    message: 'Please input the shelf name!',
                },
            ]}>
            <Input placeholder="Shelf name"/>
        </Form.Item>
    </Modal>;

}