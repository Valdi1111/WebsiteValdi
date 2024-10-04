import {Checkbox, Divider, Table} from 'antd';
import React from 'react';

export default function StandardTable({ backendFunction }) {
    const [columns, setColumns] = React.useState([]);
    const [data, setData] = React.useState([]);
    const [loading, setLoading] = React.useState(false);
    const [tableParams, setTableParams] = React.useState({
        pagination: {
            current: 1,
            pageSize: 10,
        },
        sortOrder: 'descend',
        sortField: 'id',
    });

    React.useEffect(() => {
        fetchData();
    }, [
        tableParams.pagination?.current,
        tableParams.pagination?.pageSize,
        tableParams?.sortOrder,
        tableParams?.sortField,
        JSON.stringify(tableParams.filters),
    ]);

    function fetchData() {
        setLoading(true);
        backendFunction(tableParams).then(
            res => {
                setColumns(res.data.columns);
                setData(res.data.rows);
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

    function handleTableChange(pagination, filters, sorter) {
        setTableParams({
            pagination,
            filters,
            sortOrder: Array.isArray(sorter) ? undefined : sorter.order,
            sortField: Array.isArray(sorter) ? undefined : sorter.field,
        });

        // `dataSource` is useless since `pageSize` changed
        if (pagination.pageSize !== tableParams.pagination?.pageSize) {
            setData([]);
        }
    }

    return <>
        <Divider>Columns displayed</Divider>
        <Checkbox.Group
            value={columns.filter(c => !c.hidden).map(c => c.dataIndex)}
            options={columns.map(c => ({label: c.title, value: c.dataIndex}))}
            onChange={values => {
                console.log(values)
                const cols = [];
                for (const colsKey in columns) {
                    cols[colsKey] = {...columns[colsKey], hidden: !values.includes(columns[colsKey].dataIndex)};
                }
                setColumns(cols);
            }}
        />
        <Table
            columns={columns}
            rowKey={(record) => record.id}
            dataSource={data}
            pagination={tableParams.pagination}
            loading={loading}
            onChange={handleTableChange}
        />
    </>;

}