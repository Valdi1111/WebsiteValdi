import { getDownloads } from "@AnimeBundle/api";
import {Checkbox, Divider, Table} from 'antd';
import React from 'react';

const baseColumns = [
    {
        title: 'ID',
        dataIndex: 'id',
        sorter: true,
        sortDirections: ['ascend', 'descend', 'ascend'],
        defaultSortOrder: 'descend',
    },
    {
        title: 'AnimeWorld URL',
        dataIndex: 'episode_url',
    },
    {
        title: 'Download URL',
        dataIndex: 'download_url',
        hidden: true,
    },
    {
        title: 'File',
        dataIndex: 'file',
        hidden: true,
    },
    {
        title: 'Folder',
        dataIndex: 'folder',
    },
    {
        title: 'Episode',
        dataIndex: 'episode',
    },
    {
        title: 'Created',
        dataIndex: 'created',
        hidden: true,
    },
    {
        title: 'Started',
        dataIndex: 'started',
    },
    {
        title: 'Completed',
        dataIndex: 'completed',
    },
    {
        title: 'State',
        dataIndex: 'state',
    },
    {
        title: 'MAL',
        dataIndex: 'mal_id',
    },
    {
        title: 'AL',
        dataIndex: 'al_id',
        hidden: true,
    },
];

export default function TableDownloads() {
    const [columns, setColumns] = React.useState(baseColumns);
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
        getDownloads(tableParams).then(
            res => {
                const cols = columns;
                for (const filter of Object.keys(res.data.filters)) {
                    cols.find(c => c.dataIndex === filter).filters = res.data.filters[filter];
                }
                setColumns(cols);
                setData(res.data.results);
                setLoading(false);
                setTableParams({
                    ...tableParams,
                    pagination: {
                        ...tableParams.pagination,
                        total: res.data.total,
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