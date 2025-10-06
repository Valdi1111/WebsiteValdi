import FilesTableRowDropdown from "@CoreBundle/components/file-manager/files/FilesTableRowDropdown";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { formatBytes, formatDateFromTimestamp } from "@CoreBundle/format-utils";
import { FolderFilled, SearchOutlined, } from "@ant-design/icons";
import { Button, Input, Space, Table, theme as antdTheme } from "antd";
import Highlighter from "react-highlight-words";
import React from "react";

export default function FilesTable() {
    const [searchText, setSearchText] = React.useState('');
    const [searchedColumn, setSearchedColumn] = React.useState('');
    const searchInput = React.useRef(null);

    const { api, files, filesLoading, setSelectedFile, setSelectedFolder } = useFileManager();
    const { token: { controlItemBgActiveHover } } = antdTheme.useToken();

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
                backgroundColor: controlItemBgActiveHover,
                borderRadius: '5px',
                padding: '2px 0',
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
            defaultSortOrder: 'ascend',
            sortDirections: ['ascend', 'descend', 'ascend'],
            sorter: {
                compare: (a, b) => {
                    if (a.type === 'folder' && b.type !== 'folder') return -1;
                    if (a.type !== 'folder' && b.type === 'folder') return 1;
                    return 0;
                },
                multiple: 3,
            },
            render: (text, row) => {
                if (row.type === 'folder') {
                    return <FolderFilled style={{ fontSize: "24px" }}/>;
                }
                return <img src={api.fmIconUrl('small', row.type, row.extension)} alt="Logo"/>;
            },
            width: 50,
        },
        {
            title: 'Name',
            dataIndex: 'title',
            defaultSortOrder: 'ascend',
            sortDirections: ['ascend', 'descend', 'ascend'],
            sorter: {
                compare: (a, b) => a.title.localeCompare(b.title, 'it'),
                multiple: 1,
            },
            ...getColumnSearchProps('title'),
        },
        {
            title: 'Size',
            dataIndex: 'size',
            sortDirections: ['ascend', 'descend'],
            sorter: {
                compare: (a, b) => a.size - b.size,
                multiple: 1,
            },
            render: value => {
                if (value === undefined) {
                    return null;
                }
                return formatBytes(value);
            },
            width: 150,
        },
        {
            title: 'Date',
            dataIndex: 'date',
            sortDirections: ['ascend', 'descend'],
            sorter: {
                compare: (a, b) => a.date - b.date,
                multiple: 1,
            },
            render: formatDateFromTimestamp,
            width: 150,
        },
    ];

    function onRowClick(record) {
        setSelectedFile(record);
    }

    function onRowDoubleClick(record) {
        if (record.type === 'folder') {
            setSelectedFolder(record);
            return;
        }
        const link = document.createElement('a');
        link.href = api.fmDirectUrl(record.id);
        link.target = '_blank';
        link.click();
        link.remove();
    }

    return <Table
        sticky
        rowKey="id"
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
                row: (props) => {
                    const row = files.find(f => f.id === props['data-row-key']);
                    return <FilesTableRowDropdown row={row}>
                        <tr {...props} />
                    </FilesTableRowDropdown>
                }
            }
        }}
    />;

}