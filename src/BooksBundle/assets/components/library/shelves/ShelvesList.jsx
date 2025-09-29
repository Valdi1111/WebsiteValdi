import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { App, FloatButton, Form, Input, Menu, Modal } from "antd";
import { Link, useNavigate, useParams } from "react-router-dom";
import { PlusOutlined } from "@ant-design/icons";
import React from "react";

export default function ShelvesList({ shelves, refreshShelves }) {
    const [addOpen, setAddOpen] = React.useState(false);
    const [addConfirmLoading, setAddConfirmLoading] = React.useState(false);

    const [addForm] = Form.useForm();
    const { message } = App.useApp();
    const navigate = useNavigate();
    const { shelfId } = useParams();
    const api = useBackendApi();

    function onShelfAdd(data) {
        message.open({
            key: 'shelf-add-loader',
            type: 'loading',
            content: 'Adding shelf...',
            duration: 0,
        });
        setAddConfirmLoading(true);
        api.shelves.add(data.path, data.name).then(
            res => {
                message.open({
                    key: 'shelf-add-loader',
                    type: 'success',
                    content: 'Shelf added successfully',
                    duration: 2.5,
                });
                navigate(`/library/shelves/${res.data.id}`);
                refreshShelves();
                setAddConfirmLoading(false);
                setAddOpen(false);
            },
            err => console.error(err)
        );
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
                    name="form_in_modal"
                    clearOnDestroy={true}
                    onFinish={(data) => onShelfAdd(data)}>
                    {dom}
                </Form>
            }
        >
            <Form.Item
                label="Path"
                name="path"
                extra="Insert a folder without the / at the end."
                rules={[
                    {
                        required: true,
                        message: 'Please input the shelf path!',
                    },
                ]}>
                <Input placeholder="Shelf path"/>
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
