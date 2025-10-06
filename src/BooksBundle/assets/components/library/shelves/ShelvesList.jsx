import FileManagerTreeSelect from "@CoreBundle/components/file-manager/FileManagerTreeSelect";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { FolderOpenOutlined, PlusOutlined } from "@ant-design/icons";
import { Link, useNavigate, useParams } from "react-router-dom";
import { FloatButton, Form, Input, Menu, Modal } from "antd";
import React from "react";

export default function ShelvesList({ shelves, refreshShelves }) {
    const [addOpen, setAddOpen] = React.useState(false);
    const [addConfirmLoading, setAddConfirmLoading] = React.useState(false);

    const [addForm] = Form.useForm();
    const navigate = useNavigate();
    const { shelfId } = useParams();
    const api = useBackendApi();

    function onShelfAdd(data) {
        setAddConfirmLoading(true);
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
                setAddConfirmLoading(false);
                setAddOpen(false);
            });
    }

    const items = React.useMemo(() => {
        return shelves.map(shelf => ({
            key: shelf.id,
            label: <Link to={"/library/shelves/" + shelf.id}>{shelf.name}</Link>,
        }));
    }, [shelves]);

    return <>
        <Modal
            open={addOpen}
            title={<span>Add shelf</span>}
            onCancel={() => setAddOpen(false)}
            destroyOnHidden
            okButtonProps={{
                autoFocus: true,
                htmlType: 'submit',
            }}
            confirmLoading={addConfirmLoading}
            modalRender={(dom) =>
                <Form
                    form={addForm}
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
        </Modal>
        <Menu
            selectedKeys={[shelfId]}
            style={{ flex: 1, minWidth: 0 }}
            items={items}
        />
        <FloatButton icon={<PlusOutlined/>} tooltip={'Add shelf'} onClick={() => setAddOpen(true)}/>
    </>;
}
