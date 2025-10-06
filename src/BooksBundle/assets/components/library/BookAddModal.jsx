import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { App, Checkbox, Collapse, Flex, Form, Modal, Space } from "antd";
import { CaretRightOutlined } from "@ant-design/icons";
import React from "react";

/**
 * Modal
 * @param open
 * @param setOpen
 * @returns {JSX.Element}
 * @constructor
 */
export default function BookAddModal({ open, setOpen }) {
    const [loading, setLoading] = React.useState(true);
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [content, setContent] = React.useState([]);
    const [selectedAmount, setSelectedAmount] = React.useState(0);

    const [form] = Form.useForm();
    const { message } = App.useApp();
    const api = useBackendApi();

    function afterOpenChange(opened) {
        if (!opened) {
            setLoading(true);
            setContent({});
            setSelectedAmount(0);
            return;
        }
        setLoading(true);
        api
            .withErrorHandling()
            .books()
            .findNew()
            .then(res => {
                const data = [];
                for (const [key, value] of Object.entries(res.data)) {
                    data.push({
                        key: key,
                        label: key,
                        children: <Flex vertical>
                            {value.map(b =>
                                <Form.Item key={b.path} name={b.path} valuePropName="checked" initialValue={false}
                                           noStyle>
                                    <Checkbox>{b.file}</Checkbox>
                                </Form.Item>
                            )}
                        </Flex>,
                    });
                }
                setContent(data);
                setLoading(false);
            });
    }

    function onDataChange(changed, data) {
        const selectedPaths = Object.entries(data)
            .filter(([key, value]) => value)
            .map(([key, value]) => key);
        setSelectedAmount(selectedPaths.length);
    }

    function onBooksAdd(data) {
        const selectedPaths = Object.entries(data)
            .filter(([key, value]) => value)
            .map(([key, value]) => key);
        message.open({
            key: 'books-add-loader',
            type: 'loading',
            content: 'Adding books...',
            duration: 0,
        });
        setConfirmLoading(true);
        // TODO one loading message for every book and promise for the modal?
        Promise.all(selectedPaths.map(path => api.withErrorHandling().books().create(path))).then(
            res => {
                message.open({
                    key: 'books-add-loader',
                    type: 'success',
                    content: 'Books added successfully',
                    duration: 2.5,
                });
                setConfirmLoading(false);
                setOpen(false);
            },
            err => console.error(err)
        );
    }

    return <Modal
        open={open}
        title={'Add books to Library'}
        footer={(parent, { OkBtn, CancelBtn }) =>
            <Flex align="center" justify="space-between">
                <span>{selectedAmount} items selected</span>
                <Space>
                    <CancelBtn/>
                    <OkBtn/>
                </Space>
            </Flex>
        }
        onCancel={() => setOpen(false)}
        destroyOnHidden
        okButtonProps={{ htmlType: 'submit' }}
        loading={loading}
        confirmLoading={confirmLoading}
        afterOpenChange={afterOpenChange}
        okText={'Add'}
        modalRender={(dom) =>
            <Form
                form={form}
                layout="vertical"
                name="add_books_modal"
                clearOnDestroy={true}
                onValuesChange={onDataChange}
                onFinish={onBooksAdd}>
                {dom}
            </Form>
        }
        styles={{ body: { overflowY: 'auto', maxHeight: '85vh' } }}
        centered
    >
        <Collapse
            expandIcon={({ isActive }) => <CaretRightOutlined rotate={isActive ? 90 : 0}/>}
            items={content}
            bordered={false}
            accordion
        />
    </Modal>;
}
