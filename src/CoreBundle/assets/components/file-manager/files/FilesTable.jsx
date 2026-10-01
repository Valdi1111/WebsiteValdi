import FilesTableRowDropdown from "@CoreBundle/components/file-manager/files/FilesTableRowDropdown";
import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { formatBytes, formatDateFromTimestamp } from "@CoreBundle/format-utils";
import { FolderFilled, MoreOutlined, SearchOutlined } from "@ant-design/icons";
import { Button, Flex, Input, Space, Table, theme as antdTheme } from "antd";
import Highlighter from "react-highlight-words";
import React from "react";
import "./FilesTable.css";

export default function FilesTable() {
    const [searchText, setSearchText] = React.useState('');
    const [searchedColumn, setSearchedColumn] = React.useState('');
    const searchInput = React.useRef(null);

    const {
        files, filesLoading,
        selectedFile, setSelectedFile, setSelectedFolder,
        showPreview, setShowPreview,
        isMobile, api,
    } = useFileManager();

    // Retrieve active theme tokens (Light or Dark) for row highlighting
    const { token: { controlItemBgActive, controlItemBgActiveHover } } = antdTheme.useToken();

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
        render: text => searchedColumn === dataIndex ? (
            <Highlighter
                highlightStyle={{
                    backgroundColor: controlItemBgActiveHover,
                    borderRadius: '5px',
                    padding: '2px 0',
                }}
                searchWords={[searchText]}
                autoEscape
                textToHighlight={text ? text.toString() : ''}
            />
        ) : (
            <span>{text}</span>
        )
    });

    const columns = [
        {
            title: 'Name',
            dataIndex: 'title',
            defaultSortOrder: 'ascend',
            sortDirections: ['ascend', 'descend', 'ascend'],
            sorter: (a, b) => {
                // Ensure folders always precede regular files
                if (a.type === 'folder' && b.type !== 'folder') return -1;
                if (a.type !== 'folder' && b.type === 'folder') return 1;
                return a.title.localeCompare(b.title, 'it');
            },
            ...getColumnSearchProps('title'),
            render: (text, row) => {
                const icon = row.type === 'folder' ? (
                    <FolderFilled style={{ fontSize: 24, color: '#faad14', flexShrink: 0 }} />
                ) : (
                    <img
                        src={api.fmIconUrl('small', row.type, row.extension)}
                        alt=""
                        style={{ width: 24, height: 24, objectFit: 'contain', flexShrink: 0 }}
                    />
                );

                const label = searchedColumn === 'title' ? (
                    <Highlighter
                        highlightStyle={{
                            backgroundColor: controlItemBgActiveHover,
                            borderRadius: '5px',
                            padding: '2px 0',
                        }}
                        searchWords={[searchText]}
                        autoEscape
                        textToHighlight={text ? text.toString() : ''}
                    />
                ) : (
                    <span>{text}</span>
                );

                return (
                    <Flex align="center" gap={10} style={{ minWidth: 0 }}>
                        {icon}
                        <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {label}
                        </div>
                    </Flex>
                );
            },
            className: 'fm-table-title-td',
        },
        {
            title: 'Size',
            dataIndex: 'size',
            width: 110,
            render: (size, row) => row.type === 'folder' ? '-' : formatBytes(size),
            sortDirections: ['ascend', 'descend'],
            sorter: (a, b) => {
                // Keep folders on top even when sorting by file size
                if (a.type === 'folder' && b.type !== 'folder') return -1;
                if (a.type !== 'folder' && b.type === 'folder') return 1;
                return (a.size || 0) - (b.size || 0);
            },
            className: 'fm-table-size-td',
        },
        {
            title: 'Date',
            dataIndex: 'date',
            width: 150,
            sortDirections: ['ascend', 'descend'],
            sorter: (a, b) => {
                // Keep folders on top even when sorting by modification timestamp
                if (a.type === 'folder' && b.type !== 'folder') return -1;
                if (a.type !== 'folder' && b.type === 'folder') return 1;
                return (a.date || 0) - (b.date || 0);
            },
            render: formatDateFromTimestamp,
            className: 'fm-table-date-td',
        },
        {
            title: '',
            key: 'actions',
            width: 44,
            align: 'center',
            fixed: 'right', // Pin the action column to the right edge during horizontal scrolling
            render: (_, row) => (
                // Prevent bubbling so clicking the action trigger doesn't toggle row selection
                <div onClick={(e) => e.stopPropagation()}>
                    <FilesTableRowDropdown row={row} trigger={['click']}>
                        <Button
                            type="text"
                            size="small"
                            icon={<MoreOutlined style={{ fontSize: 18 }} />}
                        />
                    </FilesTableRowDropdown>
                </div>
            ),
        }
    ];

    function onRowClick(record) {
        if (isMobile) {
            if (record.type === 'folder') {
                // Directly navigate into folder on mobile to avoid double-tap issues
                setSelectedFolder(record);
                return;
            }
            // For files: select and immediately open the preview drawer
            setSelectedFile(record);
            setShowPreview?.(true);
            return;
        }

        // Desktop default: select row on single click
        setSelectedFile(record);
    }

    function onRowDoubleClick(record) {
        if (record.type === 'folder') {
            setSelectedFolder(record);
            return;
        }
        // Direct download / open link in a new browser tab on desktop double-click
        const link = document.createElement('a');
        link.href = api.fmDirectUrl(record.id);
        link.target = '_blank';
        link.click();
        link.remove();
    }

    return (
        <div
            style={{
                height: '100%',
                width: '100%',
                minWidth: 0,
                overflowY: 'auto',   // Delegate vertical scrolling to container for sticky headers
                overflowX: 'hidden', // Horizontal overflow is handled by Ant Design's scroll.x
                // Dynamic CSS variables bound to the current Ant Design theme
                '--fm-selected-bg': controlItemBgActive,
                '--fm-selected-hover-bg': controlItemBgActiveHover,
            }}
        >
            <Table
                size="middle"
                className="fm-table"
                sticky
                rowKey="id"
                columns={columns}
                dataSource={files}
                loading={filesLoading}
                pagination={false}
                scroll={{ x: 600 }} // Enable horizontal scroll when viewport width is below 600px
                // Highlight the currently selected row
                rowClassName={(record) => (record.id === selectedFile?.id ? 'fm-table-row-selected' : '')}
                onRow={(record, rowIndex) => ({
                    onClick: e => onRowClick(record, rowIndex, e),
                    onDoubleClick: e => onRowDoubleClick(record, rowIndex, e),
                })}
                components={{
                    body: {
                        row: (props) => {
                            const row = files.find(f => f.id === props['data-row-key']);
                            return (
                                <FilesTableRowDropdown row={row}>
                                    <tr {...props} className={`${props.className ?? ''} fm-table-tr`} />
                                </FilesTableRowDropdown>
                            );
                        }
                    }
                }}
            />
        </div>
    );
}