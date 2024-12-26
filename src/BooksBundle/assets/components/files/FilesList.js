import FilesRowDropdown from "@BooksBundle/components/files/FilesRowDropdown";
import { getFiles } from "@BooksBundle/api/files";
import { Button, Input, Space, Table } from "antd";
import { SearchOutlined } from "@ant-design/icons";
import Highlighter from 'react-highlight-words';
import { DateTime } from "luxon";
import React from "react";

export default function FilesList() {
    const [dataSource, setDataSource] = React.useState([]);
    const [loading, setLoading] = React.useState(false);
    const [rowDropdownOpen, setRowDropdownOpen] = React.useState(false);
    const [rowDropdownPos, setRowDropdownPos] = React.useState({ x: 0, y: 0 });
    const [searchText, setSearchText] = React.useState('');
    const [searchedColumn, setSearchedColumn] = React.useState('');
    const searchInput = React.useRef(null);
    React.useEffect(() => {
        getFiles().then(
            res => {
                setLoading(true);
                setDataSource(res.data?.sort((a, b) => a.value.localeCompare(b.value, 'it')));
                setLoading(false);
            },
            err => console.error(err)
        )
    }, []);

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
        filterIcon: filtered => <SearchOutlined style={{ color: filtered ? '#1677ff' : undefined }}/>,
        onFilter: (value, record) => record[dataIndex].toString().toLowerCase().includes(value.toLowerCase()),
        onFilterDropdownOpenChange: visible => {
            if (visible) {
                setTimeout(() => searchInput.current?.select(), 100);
            }
        },
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

    function readableBytes(bytes) {
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        return (bytes / Math.pow(1024, i)).toFixed(2) * 1 + ' ' + sizes[i];
    }

    function readableDate(date) {
        return DateTime.fromSeconds(date).setLocale('it').toLocaleString();
    }

    const columns = [
        {
            title: 'Name',
            dataIndex: 'value',
            defaultSortOrder: 'ascend',
            sortDirections: ['ascend', 'descend', 'ascend'],
            sorter: (a, b) => a.value.localeCompare(b.value, 'it'),
            ...getColumnSearchProps('value'),
        },
        {
            title: 'Size',
            dataIndex: 'size',
            sortDirections: ['ascend', 'descend'],
            sorter: (a, b) => a.size - b.size,
            render: readableBytes,
            width: 150,
        },
        {
            title: 'Date',
            dataIndex: 'date',
            sortDirections: ['ascend', 'descend'],
            sorter: (a, b) => a.date - b.date,
            render: readableDate,
            width: 150,
        },
    ];

    function onRowClick(e) {
        console.log("onRowClick", e);
        return {};
    }

    function onRowDoubleClick(e) {
        console.log("onRowDoubleClick", e);
        return {};
    }

    function onRowContextMenu(e) {
        e.preventDefault();
        setRowDropdownPos({ x: e.clientX, y: e.clientY });
        setRowDropdownOpen(true);
        return {};
    }

    return <>
        <FilesRowDropdown open={rowDropdownOpen} setOpen={setRowDropdownOpen} pos={rowDropdownPos} setPos={setRowDropdownPos}/>
        <Table
            rowKey="id"
            columns={columns}
            dataSource={dataSource}
            loading={loading}
            pagination={false}
            scroll={{ y: 55 * 5 }}
            onRow={(record, rowIndex) => {
                return {
                    onClick: onRowClick, // click row
                    onDoubleClick: onRowDoubleClick, // double click row
                    onContextMenu: onRowContextMenu, // right button click row
                };
            }}
        />
    </>;

}