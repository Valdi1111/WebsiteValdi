import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { App, Checkbox, Collapse, Flex, Form, Modal, Space } from "antd";
import { CaretRightOutlined } from "@ant-design/icons";
import React from "react";

/**
 * Modal
 * @param {boolean} visible
 * @param {(visible: boolean) => void} setVisible
 * @returns {JSX.Element}
 * @constructor
 */
export default function BookAddModal({ visible, setVisible }) {
    const [loading, setLoading] = React.useState(true);
    const [confirmLoading, setConfirmLoading] = React.useState(false);
    const [content, setContent] = React.useState([]);
    const [selectedAmount, setSelectedAmount] = React.useState(0);

    const [form] = Form.useForm();
    const { message } = App.useApp();
    const api = useBackendApi();

    const afterOpenChange = React.useCallback((opened) => {
        setLoading(true);
        if (!opened) {
            setContent([]);
            setSelectedAmount(0);
            return;
        }
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
    }, []);

    const onDataChange = React.useCallback((changed, data) => {
        const selectedPaths = Object.entries(data)
            .filter(([key, value]) => value)
            .map(([key, value]) => key);
        setSelectedAmount(selectedPaths.length);
    }, []);

    const onBooksAdd = React.useCallback((data) => {
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
        Promise
            .all(selectedPaths.map(path => api.withErrorHandling().books().create(path)))
            .then(
                res => {
                    message.open({
                        key: 'books-add-loader',
                        type: 'success',
                        content: 'Books added successfully',
                        duration: 2.5,
                    });
                    setConfirmLoading(false);
                    setVisible(false);
                },
                err => {
                    message.destroy('books-add-loader')
                    console.error(err);
                }
            );
    }, []);

    return <Modal
        open={visible}
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
        onCancel={() => setVisible(false)}
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
