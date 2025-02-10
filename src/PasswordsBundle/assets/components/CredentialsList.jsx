import CredentialDetailModal from "@PasswordsBundle/components/credential/CredentialDetailModal";
import { Button, Checkbox, Divider, FloatButton, Space, Table, Tag } from "antd";
import React from 'react';
import { getCredentials } from "@PasswordsBundle/api";
import { DeleteOutlined, EditOutlined, PlusOutlined } from "@ant-design/icons";

export default function CredentialsList() {
    const [modalOpen, setModalOpen] = React.useState(false);
    const [credential, setCredential] = React.useState({});

    const [columns, setColumns] = React.useState([
        {
            key: 'id',
            dataIndex: 'id',
            title: 'ID',
            hidden: true,
        },
        {
            key: 'name',
            dataIndex: 'name',
            title: 'Name',
        },
        {
            key: 'tags',
            dataIndex: 'tags',
            title: 'Tags',
            render: tags => {
                if (!tags) {
                    return <></>;
                }
                return <>
                    {tags.map((tag) => {
                        let color = tag.length > 5 ? 'geekblue' : 'green';
                        return <Tag color={color} key={tag}>
                            {tag.toUpperCase()}
                        </Tag>;
                    })}
                </>
            },
        },
        {
            title: 'Action',
            key: 'action',
            dataIndex: '',
            fixed: 'right',
            render: (_, record) => (
                <Space size="middle">
                    <Button icon={<EditOutlined/>} onClick={() => {
                        setModalOpen(true);
                        setCredential({ id: record.id, type: record.type });
                    }}>Edit</Button>
                    <Button icon={<DeleteOutlined/>} danger>Delete</Button>
                </Space>
            ),
        },
    ]);
    const [data, setData] = React.useState([]);
    const [loading, setLoading] = React.useState(false);
    const [tableParams, setTableParams] = React.useState({
        sortOrder: 'ascend',
        sortField: 'id',
    });

    React.useEffect(fetchData, [
        JSON.stringify(tableParams.filters),
        tableParams?.sortOrder,
        tableParams?.sortField,
    ]);

    function fetchData() {
        setLoading(true);
        getCredentials(tableParams).then(
            res => {
                setData(res.data);
                setLoading(false);
                setTableParams({
                    ...tableParams,
                    pagination: {
                        ...tableParams.pagination,
                        total: res.data.count,
                    },
                });
            }
        );
    }

    function handleTableChange(pagination, filters, sorter, extra) {
        setTableParams({
            filters,
            sortOrder: Array.isArray(sorter) ? undefined : sorter.order,
            sortField: Array.isArray(sorter) ? undefined : sorter.field,
        });
    }

    return <>
        <CredentialDetailModal id={credential.id} type={credential.type} open={modalOpen} setOpen={setModalOpen}/>
        <Divider>Columns displayed</Divider>
        <Checkbox.Group
            value={columns.filter(c => !c.hidden).map(c => c.dataIndex)}
            options={columns.map(c => ({ label: c.title, value: c.dataIndex }))}
            onChange={values => {
                const cols = [];
                for (const colsKey in columns) {
                    cols[colsKey] = { ...columns[colsKey], hidden: !values.includes(columns[colsKey].dataIndex) };
                }
                setColumns(cols);
            }}
        />
        <Table
            columns={columns}
            rowKey={(record) => record.id}
            dataSource={data}
            loading={loading}
            onChange={handleTableChange}
            pagination={false}
        />
        <FloatButton.Group shape="circle" style={{ insetInlineEnd: 24 }}>
        <FloatButton icon={<PlusOutlined/>} type="primary" tooltip={<div>Add Device</div>} onClick={() => {
            setModalOpen(true);
            setCredential({ id: null, type: 'device' });
        }}/>
        <FloatButton icon={<PlusOutlined/>} type="primary" tooltip={<div>Add Website</div>} onClick={() => {
            setModalOpen(true);
            setCredential({ id: null, type: 'website' });
        }}/>
        </FloatButton.Group>
    </>;

}