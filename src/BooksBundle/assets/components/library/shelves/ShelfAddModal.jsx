import FileManagerTreeSelect from "@CoreBundle/components/file-manager/FileManagerTreeSelect";
import { useShelves } from "@BooksBundle/components/library/shelves/ShelvesContext";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { FolderOpenOutlined } from "@ant-design/icons";
import { Form, Input, Modal } from "antd";
import { useNavigate } from "react-router";
import React from "react";

/**
 * Modal
 * @param {boolean} visible
 * @param {(visible: boolean) => void} setVisible
 * @returns {JSX.Element}
 * @constructor
 */
export default function ShelfAddModal({ visible, setVisible }) {
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [form] = Form.useForm();

    const navigate = useNavigate();
    const { selectedShelf, refreshShelves } = useShelves();
    const api = useBackendApi();

    const onShelfAdd = React.useCallback((data) => {
        if (!selectedShelf) {
            return;
        }
        setConfirmLoading(true);
        api
            .withLoadingMessage({
                key: 'shelf-add-loader',
                loadingContent: 'Adding shelf...',
                successContent: 'Shelf added successfully',
            })
            .shelves()
            .add(data.path, data.name)
            .then(res => {
                navigate(`/library/shelves/${res.data.id}`);
                refreshShelves();
                setVisible(false);
            })
            .finally(() => setConfirmLoading(false));
    }, [selectedShelf?.id]);

    return <Modal
        open={visible}
        title={<span>Add shelf</span>}
        onCancel={() => setVisible(false)}
        destroyOnHidden
        okButtonProps={{
            autoFocus: true,
            htmlType: 'submit',
        }}
        confirmLoading={confirmLoading}
        modalRender={(dom) =>
            <Form
                form={form}
                layout="vertical"
                name="add_shelf_modal"
                clearOnDestroy={true}
                onFinish={(data) => onShelfAdd(data)}>
                {dom}
            </Form>
        }
    >
        <Form.Item
            label="Path"
            name="path"
            rules={[{ required: true, message: 'Please input shelf path.' }]}
        >
            <FileManagerTreeSelect
                apiUrl={api.fmUrl()}
                prefix={<FolderOpenOutlined/>}
                placeholder="Path"
                showSearch
                treeLine
                showAddButton={false}
                style={{ width: '100%' }}
                styles={{
                    popup: { root: { maxHeight: 400, overflow: 'auto' } },
                }}
            />
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