import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import FilesTableRowDropdown from "@CoreBundle/components/file-manager/files/FilesTableRowDropdown";
import { formatBytes, formatDateFromTimestamp } from "@CoreBundle/utils";
import { FolderOutlined, SearchOutlined, } from "@ant-design/icons";
import { Button, Input, Space, Table } from "antd";
import Highlighter from "react-highlight-words";
import React from "react";

export default function FilesTable() {
    const { api, files, filesLoading, setSelectedKey } = useFileManager();
    const [searchText, setSearchText] = React.useState('');
    const [searchedColumn, setSearchedColumn] = React.useState('');
    const searchInput = React.useRef(null);

    const handleSearch = (selectedKeys, confirm, dataIndex, closeDropdown = false) => {
        confirm({ closeDropdown });
        setSearchText(selectedKeys[0]);
        setSearchedColumn(dataIndex);
    };

    const handleReset = clearFilters => {
        clearFilters();
        setSearchText('');
    };

    const getColumnSearchProps = dataIndex => ({
        filterDropdown: ({ setSelectedKeys, selectedKeys, confirm, clearFilters, close }) => (
            <div onKeyDown={e => e.stopPropagation()} style={{ padding: 8 }}>
                <Input
                    ref={searchInput}
                    placeholder={`Search ${dataIndex}`}
                    value={selectedKeys[0]}
                    onChange={e => setSelectedKeys(e.target.value ? [e.target.value] : [])}
                    onPressEnter={() => handleSearch(selectedKeys, confirm, dataIndex)}
                    style={{ marginBottom: 8, display: 'block' }}
                />
                <Space>
                    <Button
                        type="primary"
                        onClick={() => handleSearch(selectedKeys, confirm, dataIndex)}
                        icon={<SearchOutlined/>}
                        size="small"
                        style={{ width: 90 }}
                    >
                        Search
                    </Button>
                    <Button
                        onClick={() => clearFilters && handleReset(clearFilters)}
                        size="small"
                        style={{ width: 90 }}
                    >
                        Reset
                    </Button>
                    <Button type="link" size="small" onClick={() => close()}>Close</Button>
                </Space>
            </div>
        ),
        filterDropdownProps: {
            onOpenChange: visible => {
                if (visible) {
                    setTimeout(() => searchInput.current?.select(), 100);
                }
            }
        },
        filterIcon: filtered => <SearchOutlined style={{ color: filtered ? '#1677ff' : undefined }}/>,
        onFilter: (value, record) => record[dataIndex].toString().toLowerCase().includes(value.toLowerCase()),
        render: text => searchedColumn === dataIndex ? <Highlighter
            highlightStyle={{
                backgroundColor: '#ffc069',
                padding: 0,
            }}
            searchWords={[searchText]}
            autoEscape
            textToHighlight={text ? text.toString() : ''}
        /> : text
    });

    const columns = [
        {
            title: '',
            dataIndex: 'type',
            width: 50,
            render: (text, row) => {
                if (row.type === 'folder') {
                    return <FolderOutlined/>;
                }
                let name = '';
                if (row.title) {
                    name = row.title.split('.').pop();
                }
                return <img src={api.fmIconUrl('small', row.type, name)} alt="Logo"/>;
            },
        },
        {
            title: 'Name',
            dataIndex: 'title',
            defaultSortOrder: 'ascend',
            sortDirections: ['ascend', 'descend', 'ascend'],
            sorter: (a, b) => a.title.localeCompare(b.title, 'it'),
            ...getColumnSearchProps('title'),
        },
        {
            title: 'Size',
            dataIndex: 'size',
            sortDirections: ['ascend', 'descend'],
            sorter: (a, b) => a.size - b.size,
            render: formatBytes,
            width: 150,
        },
        {
            title: 'Date',
            dataIndex: 'date',
            sortDirections: ['ascend', 'descend'],
            sorter: (a, b) => a.date - b.date,
            render: formatDateFromTimestamp,
            width: 150,
        },
    ];

    function onRowClick(record) {
        setSelectedKey(record.key);
    }

    function onRowDoubleClick(record) {
        const link = document.createElement('a');
        link.href = api.fmDirectUrl(record.key);
        link.target = '_blank';
        link.click();
        link.remove();
    }

    return <Table
        sticky
        rowKey="key"
        columns={columns}
        dataSource={files}
        loading={filesLoading}
        pagination={false}
        scroll={{ x: 'max-content' }}
        onRow={(record, rowIndex) => {
            return {
                onClick: e => onRowClick(record, rowIndex, e), // click row
                onDoubleClick: e => onRowDoubleClick(record, rowIndex, e), // double click row
            };
        }}
        components={{
            body: {
                row: props => <FilesTableRowDropdown rowKey={props['data-row-key']}>
                    <tr {...props} />
                </FilesTableRowDropdown>
            }
        }}
    />;

}